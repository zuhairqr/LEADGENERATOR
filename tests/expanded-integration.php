<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Artisan,DB,Http};
use Illuminate\Support\Str;
use App\Models\{Lead,BusinessRecord,WebsiteAudit,IntegrationSetting,IntegrationSecret,OutboundMessage,InboxEvent,OperatorAccount,ConnectorConfig,ConnectorRun,ChannelMessage,LeadTask};
use App\Services\{PublicWebClient,BusinessIntake,WebsiteAuditService,CountryPolicy,BusinessDiscovery,ConnectorService,ChannelOutbox,InboxProcessor,MailboxSync,ProspectingProfile};
$app=require __DIR__.'/../bootstrap/app.php';$kernel=$app->make(Kernel::class);$kernel->bootstrap();
if(config('app.env')!=='local'||config('database.connections.mysql.host')!=='127.0.0.1'||config('database.connections.mysql.port')!=3310)throw new RuntimeException('Dedicated local database only');
config(['database.connections.mysql.database'=>'lead_generator_test','cache.stores.file.path'=>storage_path('local-runtime/expanded-tests-'.bin2hex(random_bytes(6))),'cache.stores.file.lock_path'=>storage_path('local-runtime/expanded-test-locks'),'leadgen.admin_email'=>'owner@example.test','leadgen.admin_password_hash'=>password_hash('Ephemeral-owner-test-password',PASSWORD_DEFAULT),'leadgen.import_token'=>bin2hex(random_bytes(32)),'leadgen.sources.business-web.permitted'=>true]);
DB::purge('mysql');$actual=str_replace('\\','/',DB::selectOne('SELECT @@datadir AS path')->path);if(strcasecmp(rtrim($actual,'/'),str_replace('\\','/',dirname(__DIR__)).'/storage/local-mysql')||DB::getDatabaseName()!=='lead_generator_test')throw new RuntimeException('Wrong test database');
Artisan::call('migrate',['--force'=>true]);Http::preventStrayRequests();DB::beginTransaction();$checks=0;$cookies=[];$failed=false;$testRemote='127.0.0.'.random_int(10,230);
function exCheck(bool $ok,string $text):void{global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$text);$checks++;echo 'PASS: '.$text.PHP_EOL;}
function exFail(callable $f,string $text):void{try{$f();}catch(Throwable $e){exCheck(true,$text);return;}throw new RuntimeException('FAIL: '.$text);}
function exVisit(string $method,string $path,array $data=[],array $files=[]){global $kernel,$cookies,$testRemote;$r=Request::create('http://127.0.0.1:8079'.$path,$method,$data,$cookies,$files,['HTTP_HOST'=>'127.0.0.1:8079','REMOTE_ADDR'=>$testRemote,'HTTP_ACCEPT'=>'text/html']);$response=$kernel->handle($r);foreach($response->headers->getCookies() as $c)$cookies[$c->getName()]=$c->getValue();$kernel->terminate($r,$response);return $response;}
function exCsrf(string $html):string{if(!preg_match('/name="_token" value="([^"]+)"/',$html,$m))throw new RuntimeException('CSRF missing');return html_entity_decode($m[1]);}
function exApi(string $path,array $data=[],bool $authorized=true){global $kernel;$r=Request::create('http://127.0.0.1:8079'.$path,'POST',$data,[],[],['REMOTE_ADDR'=>'127.0.0.245','HTTP_ACCEPT'=>'application/json','HTTP_AUTHORIZATION'=>$authorized?'Bearer '.config('leadgen.import_token'):'']);$res=$kernel->handle($r);$kernel->terminate($r,$res);return $res;}
function exLogin(string $email,string $password):string{$csrf=exCsrf(exVisit('GET','/login')->getContent());exVisit('POST','/login',['email'=>$email,'password'=>$password,'_token'=>$csrf]);return exCsrf(exVisit('GET','/')->getContent());}
class FixtureWeb extends PublicWebClient {
 public array $responses=[];public array $calls=[];
 public function fetch(string $url,int $maxBytes=1048576,array $headers=[],string $method='GET',?string $body=null,bool $sameSiteRedirects=false):array{$this->calls[]=compact('url','headers','method','body','sameSiteRedirects');$r=array_shift($this->responses);if(!$r)throw new RuntimeException('No fixture response');return array_replace(['url'=>$url,'status'=>200,'headers'=>['content-type'=>'text/html'],'body'=>'','elapsed_ms'=>18],$r);}
}
class PrivateDnsFixture extends PublicWebClient {protected function addresses(string $host):array{return ['93.184.216.34','127.0.0.1'];}}
class ExpandedProfile extends ProspectingProfile {public function bridges():array{return ['ai_ready'=>true,'inbox_ready'=>true,'gmail_ready'=>true];}}
try{
 foreach(['/discovery','/connectors','/inbox','/reports','/channels','/team'] as $path)exCheck(exVisit('GET',$path)->getStatusCode()===302,'Anonymous access is blocked: '.$path);
 $csrf=exLogin('owner@example.test','Ephemeral-owner-test-password');
 foreach(['/discovery','/connectors','/inbox','/reports','/channels','/team'] as $path)exCheck(exVisit('GET',$path)->getStatusCode()===200,'Owner screen renders: '.$path);
 $sourceCatalogHtml=exVisit('GET','/connectors')->getContent();
 exCheck(str_contains($sourceCatalogHtml,'Built-in collection sources') && str_contains($sourceCatalogHtml,'Remotive contracts') && str_contains($sourceCatalogHtml,'Custom API connections'), 'Connector screen distinguishes built-in sources from saved API connections');
 foreach(['127.0.0.1','10.2.3.4','172.16.1.1','192.168.1.1','169.254.169.254','100.64.0.2','::1','::ffff:127.0.0.1','fe80::1','fc00::1','2001:db8::1','224.0.0.1'] as $ip)exCheck(!PublicWebClient::publicAddress($ip),'Nonpublic/reserved audit target blocked: '.$ip);
 exCheck(PublicWebClient::publicAddress('1.1.1.1'),'Public address validation accepts a global address');
 foreach(['file:///etc/passwd','http://localhost/','http://127.0.0.1/','https://name:secret@company.example.com/','https://company.example.com:25/','http://2130706433/','http://0177.0.0.1/'] as $url)exFail(fn()=>PublicWebClient::url($url),'Unsafe URL is rejected');
 exFail(fn()=>(new PrivateDnsFixture)->fetch('https://company.example.com'),'Mixed public/private DNS fails before any request');
 exFail(fn()=>PublicWebClient::resolve('https://company.example.com','http://169.254.169.254/latest/meta-data'),'Redirects cannot target metadata/local addresses');
 exCheck(PublicWebClient::resolve('https://company.example.com/about/team','../contact')==='https://company.example.com/contact','Relative contact links normalize safely');
 IntegrationSetting::updateOrCreate(['key'=>'business_discovery'],['data'=>['niches'=>['All businesses'],'locations'=>[],'excluded_countries'=>['IN','PK'],'require_country_review'=>true,'enabled'=>false,'auto_audit'=>false]]);
 $intake=app(BusinessIntake::class);$one=$intake->import(['name'=>'Fixture Clinic','website'=>'https://www.clinic.example.com/','source_url'=>'https://directory.example.com/clinic','country'=>'US','niche'=>'clinic','email'=>'Contact@clinic.example.com'],'fixture-directory');$lead=$one['lead'];
 exCheck(!$one['duplicate'] && $lead->kind==='business' && $lead->business->domain==='clinic.example.com','Business intake normalizes the company domain');
 $again=$intake->import(['name'=>'Clinic duplicate name','website'=>'https://clinic.example.com/contact','source_url'=>'https://another.example.com/entry','email'=>'CONTACT@clinic.example.com'],'fixture-second');
 exCheck($again['duplicate'] && $again['lead']->id===$lead->id && $lead->business->fresh()->leads()->count()===1,'Cross-source records merge on company domain');
 exCheck($lead->provenances()->count()===2 && count($lead->business->fresh()->contacts)===1,'Source provenance is retained and emails normalize without duplication');
 exCheck(!$lead->contact_email && !$lead->contact_permission_at,'Imported contact data cannot approve outreach');
 $blocked=$intake->import(['name'=>'Excluded fixture','website'=>'https://excluded.example.com','country'=>'India'])['lead'];exCheck($blocked->status==='rejected' && !app(CountryPolicy::class)->allowed($blocked),'Indian business records are excluded');
 $pk=$intake->import(['name'=>'Pakistan fixture','website'=>'https://pakfixture.example.com','country'=>'PK'])['lead'];exCheck(!app(CountryPolicy::class)->allowed($pk),'Pakistani business records cannot receive outreach');
 $unknown=$intake->import(['name'=>'Unknown country','website'=>'https://unknown.example.com'])['lead'];exCheck(!app(CountryPolicy::class)->allowed($unknown),'Unknown business countries stay on hold');
 $discoverySetting = IntegrationSetting::findOrFail('business_discovery');
 $discoverySettings = $discoverySetting->data;
 $discoverySetting->update(['data' => array_replace($discoverySettings, ['excluded_countries' => ['AU']])]);
 exCheck(app(CountryPolicy::class)->excluded() === ['IN', 'PK', 'AU'], 'Additional exclusions cannot remove either required country exclusion');
 $discoverySetting->update(['data' => $discoverySettings]);
 $res=exVisit('POST','/leads/'.$unknown->id.'/country',['country'=>'GB','country_evidence'=>'Fixture address source','country_verified'=>1,'_token'=>$csrf]);exCheck($res->getStatusCode()===302 && app(CountryPolicy::class)->allowed($unknown->fresh()),'Country confirmation enables an allowed country without approving email');
 $web=new FixtureWeb;$html='<html><head><title>Fixture Clinic</title><script type="application/ld+json">{"@type":"LocalBusiness","address":{"addressCountry":"US"}}</script></head><body><img src="x.png"><a href="mailto:hello@clinic.example.com">Email</a><a href="/contact">Contact</a></body></html>';
 $web->responses[]=['body'=>$html];$auditor=new WebsiteAuditService($web);$audit=$auditor->run($lead);
 exCheck($audit->status==='completed' && $audit->http_status===200 && $audit->evidence['html_sha256']===hash('sha256',$html),'Audit stores fetched URL/status/hash and real HTML evidence');
 exCheck(in_array('VIEWPORT_MISSING',array_column($audit->findings,'code'),true) && in_array('IMAGE_ALT_MISSING',array_column($audit->findings,'code'),true),'Audit findings are derived from observed elements');
 exCheck($audit->evidence['declared_countries']===['US'] && in_array('hello@clinic.example.com',$audit->evidence['public_emails'],true),'Audit extracts declared country and explicit mailto contacts');
 exCheck(str_contains($audit->evidence['scope'],'no JavaScript') && !$lead->fresh()->contact_permission_at,'Audit states measurement limits and never approves contact');
 $web->responses[]=['body'=>'Not an HTML page','headers'=>['content-type'=>'application/json']];$failedAudit=$auditor->run($unknown);exCheck($failedAudit->status==='failed' && $failedAudit->error_code==='NOT_HTML','Non-HTML responses fail explicitly');
 $web->responses[]=['body'=>'<html><title>Access denied</title></html>','status'=>403];$limited=$auditor->run($unknown);exCheck($limited->status==='limited' && !$limited->score && !$limited->findings,'Access-denied pages cannot become false website-defect findings');
 exCheck(exVisit('GET','/leads/'.$lead->id)->getStatusCode()===200,'Business evidence, country review and CRM panels render');
 $contactPage = exVisit('GET', '/leads/'.$lead->id)->getContent();
 exCheck(str_contains(exVisit('GET', '/')->getContent(), '/leads/'.$lead->id.'#lead-contact') &&
     str_contains($contactPage, 'data-contact-address="hello@clinic.example.com"') &&
     str_contains($contactPage, 'href="'.e($audit->evidence['contact_links'][0]).'"'),
     'Dashboard contact links open the lead panel with discovered email and source pages');
 $direct=$intake->import(['name'=>'Direct Contact Fixture','website'=>'https://contact-page.example.com/','country'=>'CA'])['lead'];
 $directWeb=new FixtureWeb;
 $directWeb->responses=[
     ['body'=>'<html><body><a href="https://outside.example.com/contact">Contact</a><a href="/contact-us">Get in touch</a></body></html>'],
     ['body'=>'<html><body><p>Write to office@contact-page.example.com.</p><p hidden>hidden@contact-page.example.com</p><script>const ignored="script@contact-page.example.com";</script></body></html>'],
 ];
 $directAudit=(new WebsiteAuditService($directWeb))->run($direct);
 $contactUrl='https://contact-page.example.com/contact-us';
 $direct->refresh();
 exCheck($directAudit->status==='completed' && count($directWeb->calls)===2 && $directWeb->calls[1]['url']===$contactUrl && $directWeb->calls[1]['sameSiteRedirects']
     && $directAudit->evidence['public_email_sources']===['office@contact-page.example.com'=>$contactUrl]
     && array_values($direct->business->contacts)===[['email'=>'office@contact-page.example.com','source'=>$contactUrl,'verified'=>false]]
     && !$direct->contact_email && !$direct->contact_permission_at,
     'Audit follows one same-site contact page and saves the published email and source without granting permission');
 $directInbox=exVisit('GET','/?kind=business&contact=public')->getContent();
 $verifiedInbox=exVisit('GET','/?kind=business&contact=verified')->getContent();
 exCheck(str_contains($directInbox,'/leads/'.$direct->id.'#lead-contact') && !str_contains($directInbox,'/leads/'.$unknown->id.'#lead-contact') && !str_contains($verifiedInbox,'/leads/'.$direct->id.'#lead-contact'),
     'Email or phone filter includes discovered contacts while the verified filter excludes them until approval');
 $response = exVisit('PUT', '/leads/'.$unknown->id.'/contact', ['contact_email' => 'review@example.test', '_token' => $csrf]);
 exCheck($response->getStatusCode() === 302 && $unknown->fresh()->contact_email === 'review@example.test' && !$unknown->fresh()->contact_permission_at,
     'Saving an address for review does not grant contact permission');
 exCheck(exVisit('POST','/leads/'.$lead->id.'/audit')->getStatusCode()===419,'Website audit actions require CSRF');
 exVisit('POST','/leads/'.$lead->id.'/notes',['body'=>'<script>note</script>','_token'=>$csrf]);exCheck(str_contains(exVisit('GET','/leads/'.$lead->id)->getContent(),'&lt;script&gt;note&lt;/script&gt;'),'CRM notes persist as escaped text');
 exVisit('POST','/leads/'.$lead->id.'/tasks',['title'=>'Review clinic website','due_at'=>'2026-11-04T10:00','_token'=>$csrf]);$task=LeadTask::where('lead_id',$lead->id)->first();exCheck($task?->due_at->format('H:i')==='05:00','CRM action times convert Asia/Karachi input to UTC');
 exVisit('POST', '/leads/'.$lead->id.'/tasks', ['title' => 'Later check', 'due_at' => '2026-11-06T10:00', '_token' => $csrf]);
 $later = $lead->tasks()->where('title', 'Later check')->firstOrFail();
 exCheck($lead->fresh()->next_action_at->equalTo($task->due_at), 'A later task does not replace the earliest pending action');
 exVisit('POST', '/tasks/'.$task->id.'/complete', ['_token' => $csrf]);
 exCheck($lead->fresh()->next_action_at->equalTo($later->due_at), 'Completing the earliest task advances the next action');
 exVisit('POST','/tasks/'.$later->id.'/complete',['_token'=>$csrf]);exCheck($later->fresh()->completed_at!==null && !$lead->fresh()->next_action_at,'Completing the last action clears the next-action marker');
 $file=storage_path('local-runtime/fixture-business-'.bin2hex(random_bytes(5)).'.csv');file_put_contents($file,"name,website,country,email\nCSV Company,https://csv.example.com,CA,hello@csv.example.com\nCSV duplicate,https://www.csv.example.com/contact,CA,HELLO@csv.example.com\nBad row\n");
 $upload=new UploadedFile($file,'fixture.csv','text/csv',null,true);$res=exVisit('POST','/discovery/csv',['permission'=>1,'_token'=>$csrf],['file'=>$upload]);exCheck($res->getStatusCode()===302 && Lead::where('source','csv-business')->count()===1,'CSV upload imports valid rows and merges duplicates');
 exCheck(!Lead::where('source','csv-business')->first()->contact_permission_at,'CSV cannot assign sending permission');
 $quality=app(\App\Services\BusinessProspectQuality::class);$qualitySkills=['Laravel'=>['laravel'],'Websites'=>['website','wordpress'],'SEO'=>['seo']];
 exCheck($quality::blockedHost('www.upwork.com') && $quality::blockedHost('www.freelancer.com') && !$quality::blockedHost('contact-page.example.com'), 'Direct business discovery excludes job marketplaces while permitting independent company sites');
 foreach(['learn.microsoft.com','apps.microsoft.com','chromewebstore.google.com','store.steampowered.com','dictionary.com'] as $host)exCheck(\App\Services\BusinessProspectQuality::blockedHost($host),'Content/platform host and subdomains filtered: '.$host);
 exCheck(!\App\Services\BusinessProspectQuality::blockedHost('microsoft-dental.example.com'),'Host filtering uses parent-domain boundaries');
 $qualityRow=['title'=>'Independent Dental Clinic','url'=>'https://dental.example.com/contact','content'=>'Public company contact for our dental clinic. Book an appointment.'];
 $qualityResult=$quality->assess($qualityRow,'dental clinics',$qualitySkills);
 exCheck($qualityResult['accepted'] && !$qualityResult['buyer_request'] && !$qualityResult['service_matches'],'Real business relevance does not fabricate a request for digital services');
 exCheck(!$quality->assess(array_replace($qualityRow,['url'=>'https://dentists.example.com/blog/best-clinics']),'dental clinics',$qualitySkills)['accepted'],'Editorial article URLs cannot become direct business prospects');
 exCheck(!$quality->assess(array_replace($qualityRow,['title'=>'Web design agency for dental clinics','content'=>'We offer web design services for dentists. Contact our agency.']),'dental clinics',$qualitySkills)['accepted'],'Service sellers are not confused with buyers');
 exCheck(!$quality->assess(array_replace($qualityRow,['title'=>'Appointment scheduling software for dental clinics']),'dental clinics',$qualitySkills)['accepted'],'Industry software vendors are not confused with the businesses they serve');
 foreach(['Accounting Firm Answering Service','Answering Services for Accountants','6 Best AI Receptionists for Accountants','Accounting Answering Service: A Tax Season Guide'] as $vendorTitle)exCheck(!$quality->assess(array_replace($qualityRow,['title'=>$vendorTitle]),'dental clinics',$qualitySkills)['accepted'],'Vendor/editorial result filtered without relying on URL path: '.$vendorTitle);
 exCheck($quality->assess(['title'=>'Acme SaaS Software','url'=>'https://saas.example.com/contact','content'=>'Software platform for accounting firms. Contact our team.'],'SaaS companies',$qualitySkills)['accepted'],'An explicit SaaS target can still discover SaaS businesses');
 exCheck($quality->assess(array_replace($qualityRow,['title'=>'Book an appointment | Acme Dental']),'dental clinics',$qualitySkills)['name']==='Acme Dental','Business names are separated from generic booking page titles');
 $qualityRequest=array_replace($qualityRow,['content'=>'Our dental clinic is looking for a Laravel developer. Contact our practice.']);
 $requestQuality=$quality->assess($qualityRequest,'dental clinics',$qualitySkills,'service-request');
 exCheck($requestQuality['accepted'] && $requestQuality['buyer_request'] && in_array('Laravel',$requestQuality['service_matches'],true),'Explicit buyer requests must match an offered skill');
 exCheck(!$quality->assess($qualityRequest,'dental clinics',['Automation'=>['n8n']],'service-request')['accepted'],'Requests for services outside the saved skills are filtered');
 exCheck($quality->assess(array_replace($qualityRow,['title'=>'Indianapolis Dental Clinic']),'dental clinics',$qualitySkills)['accepted'],'Excluded-country filtering does not match inside Indianapolis');
 $qualityPlan=$quality->plan('All businesses','',3,['Laravel'=>['laravel']]);
 exCheck($qualityPlan['mode']==='service-request' && str_contains($qualityPlan['query'],'laravel') && !str_contains($qualityPlan['query'],'"store"'),'All-business queries rotate concrete sectors and use the saved services');
 $weakFit=$quality->auditFit([['code'=>'META_DESCRIPTION_MISSING','severity'=>'medium']],$qualitySkills);
 exCheck($weakFit['score']<52 && in_array('SEO',$weakFit['services'],true),'A missing meta description alone cannot produce a high-priority opportunity');
 $unoffered=$quality->auditFit([['code'=>'META_DESCRIPTION_MISSING','severity'=>'medium']],['Accounting'=>['bookkeeping']]);
 exCheck(!$unoffered['services'] && $unoffered['score']<52,'Audit evidence is matched against services actually offered');
 Http::fake(['127.0.0.1:8082/*'=>Http::response(['results'=>[['title'=>'Independent Dental Clinic','url'=>'https://canadian.example.com','content'=>'Public company contact for our dental clinic. Book an appointment.'],['title'=>'Indian business in India','url'=>'https://company.in','content'=>'contact'],['title'=>'Dental resources','url'=>'https://learn.microsoft.com/dental','content'=>'Dental contact information']]])]);
 $search=app(BusinessDiscovery::class)->search('All businesses','',false);exCheck(count($search['items'])===1 && str_contains($search['items'][0]['description'],'Public company'),'Business search retains public candidates and filters excluded-country results');
 exCheck($search['quality']['filtered']===2 && \App\Models\CollectionRun::find($search['run_id'])->quality_summary['accepted']===1,'Search records explainable filter counts and accepted totals');
 $qualityImported=$intake->import($search['items'][0],'business-web')['lead'];
 exCheck(!empty($qualityImported->business->discovery_evidence['signals']) && !$qualityImported->contact_permission_at && !app(CountryPolicy::class)->allowed($qualityImported),'Discovery signals persist without confirming country or granting outreach permission');
 exFail(fn()=>app(BusinessDiscovery::class)->search('All businesses'),'Repeated discovery respects source cooldown');
 \App\Models\CollectionRun::whereKey($search['run_id'])->update(['created_at' => now()->subMinutes(2)]);
 $response = exVisit('POST', '/discovery/search', ['niche' => 'dental clinics', '_token' => $csrf]);
 $resultsHtml = exVisit('GET', '/discovery')->getContent();
 $hasBatch = preg_match('/name="search_batch" value="([^"]+)"/', $resultsHtml, $batch);
 exCheck(str_ends_with($response->headers->get('Location'), '#search-results') && $hasBatch === 1, 'Search redirects to results and binds save forms to the current batch');
 $response = exVisit('POST', '/discovery/import', ['candidate' => 0, 'search_batch' => (string) Str::uuid(), 'permission' => 1, '_token' => $csrf]);
 exCheck(str_ends_with($response->headers->get('Location'), '/discovery'), 'A stale result form cannot import the newer result at the same position');
 $response = exVisit('POST', '/discovery/import', ['candidate' => 0, 'search_batch' => $batch[1], 'permission' => 1, '_token' => $csrf]);
 exCheck(str_ends_with($response->headers->get('Location'), '/leads/'.$qualityImported->id), 'A current result form saves the reviewed candidate');
 $feed=new ConnectorConfig(['id'=>(string)Str::uuid(),'name'=>'Approved fixture feed','platform'=>'Custom approved API / feed','purpose'=>'feed','endpoint'=>'https://feed.example.com/companies','enabled'=>true,'settings'=>['items_path'=>'data','mapping'=>['name'=>'company','website'=>'site','country'=>'country'],'method'=>'GET','auth_header'=>'Authorization']]);$feed->save();IntegrationSecret::create(['key'=>'cn_'.str_replace('-','',$feed->id),'data'=>['token'=>'fixture-token-not-displayed']]);
 $fakeWeb=new FixtureWeb;$fakeWeb->responses[]=['headers'=>['content-type'=>'application/json'],'body'=>json_encode(['data'=>[['company'=>'Feed company','site'=>'https://feedcompany.example.com','country'=>'GB']]])];$service=new ConnectorService($fakeWeb);$count=$service->feed($feed);
 exCheck($count['imported']===1 && ConnectorRun::where('connector_id',$feed->id)->value('status')==='completed','Approved JSON feeds map and persist business data with run history');
 $feed->enabled=false;$feed->save();exFail(fn()=>$service->feed($feed),'Disabled feed connectors cannot fetch');$feed->enabled=true;$feed->save();
 exCheck(!str_contains(exVisit('GET','/connectors')->getContent(),'fixture-token-not-displayed'),'Connector credentials never render in the source catalog');
 $apollo=new ConnectorConfig(['id'=>(string)Str::uuid(),'name'=>'Apollo fixture','platform'=>'Apollo.io','purpose'=>'feed','endpoint'=>'https://old.example.com/incorrect','enabled'=>false,'settings'=>['method'=>'GET','items_path'=>'wrong','auth_header'=>'Authorization','request_body'=>['page'=>1,'per_page'=>2,'person_titles'=>['founder']],'mapping'=>['name'=>'organization.name','website'=>'organization.website_url','country'=>'organization.country']]]);$apollo->save();
 IntegrationSecret::create(['key'=>'cn_'.str_replace('-','',$apollo->id),'data'=>['token'=>'fixture-apollo-private-key']]);
 $apolloWeb=new FixtureWeb;$apolloWeb->responses[]=['status'=>200,'headers'=>['content-type'=>'application/json'],'body'=>json_encode(['people'=>[['id'=>'person-1','organization'=>['name'=>'Nested Company','website_url'=>'https://nested.example.com','country'=>'US']]]])];
 $apolloService=new ConnectorService($apolloWeb);$beforeLeads=Lead::count();$testResult=$apolloService->testConnection($apollo);$call=$apolloWeb->calls[0];
 exCheck($testResult===['http_status'=>200,'people_count'=>1] && Lead::count()===$beforeLeads && ConnectorRun::where('connector_id',$apollo->id)->first()->status==='test_passed','Apollo connection test parses people without importing leads');
 exCheck($call['url']===ConnectorService::APOLLO_URL && $call['method']==='POST' && json_decode($call['body'],true)['person_titles']===['founder'],'Apollo uses the People API POST endpoint and configured JSON body');
 exCheck(in_array('x-api-key: fixture-apollo-private-key',$call['headers'],true) && in_array('Content-Type: application/json',$call['headers'],true) && !in_array('Authorization: Bearer fixture-apollo-private-key',$call['headers'],true),'Apollo key is sent only in x-api-key and POST uses JSON content type');
 exCheck(ConnectorService::validHeader('x-custom-api-key') && !ConnectorService::validHeader("x-api-key\r\nHost") && !ConnectorService::validHeader('Host') && ConnectorService::validPath('organization.website_url') && !ConnectorService::validPath('organization.__proto__'),'Custom header and nested mapping paths are validated');
 exCheck(!str_contains(exVisit('GET','/connectors')->getContent(),'fixture-apollo-private-key') && str_contains(exVisit('GET','/connectors')->getContent(),'Test Connection (no import)'),'Apollo secret remains backend-only and test action renders');
 foreach([400=>'BAD_REQUEST',401=>'INVALID_API_KEY',403=>'ACCESS_DENIED_OR_SCOPE',422=>'INVALID_SEARCH_FILTERS',429=>'RATE_LIMITED'] as $http=>$error){$apolloWeb->responses[]=['status'=>$http,'body'=>'{"error":"fixture-apollo-private-key must never display"}'];try{$apolloService->testConnection($apollo);throw new RuntimeException('Expected Apollo HTTP error');}catch(\App\Services\ConnectorFailure $failure){exCheck($failure->httpStatus===$http && $failure->safeCode===$error && !str_contains($failure->getMessage(),'fixture-apollo-private-key') && Lead::count()===$beforeLeads,'Apollo HTTP '.$http.' is reported safely without importing');}ConnectorRun::where('connector_id',$apollo->id)->where('status','failed')->delete();}
 exVisit('POST','/connectors/bedrock/settings',['region'=>'us-east-1','key'=>'fixture-bedrock-private-key','_token'=>$csrf]);
 $bedrockResponse=['output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>'One cited storefront requires review.','annotations'=>[['type'=>'url_citation','title'=>'Example storefront','url'=>'https://storefront.example.com/']]]]]]];
 Http::fake(['bedrock-mantle.us-east-1.api.aws/*'=>Http::response($bedrockResponse,200)]);
 $beforeBedrockLeads=Lead::count();$researchResponse=exVisit('POST','/connectors/bedrock/research',['question'=>'Review one ecommerce storefront','_token'=>$csrf]);$researchHtml=exVisit('GET','/connectors')->getContent();
 exCheck($researchResponse->getStatusCode()===302,'Bedrock research submits and redirects (HTTP '.$researchResponse->getStatusCode().')');
 exCheck(Lead::count()===$beforeBedrockLeads,'Bedrock research does not save leads');
 exCheck(str_contains($researchHtml,'Example storefront'),'Bedrock research displays source citation');
 exCheck(!str_contains($researchHtml,'fixture-bedrock-private-key'),'Bedrock research never renders the private key');
 exCheck(Http::recorded(fn($request)=>str_contains($request->url(),'bedrock-mantle.us-east-1.api.aws/openai/v1/responses') && $request->hasHeader('Authorization','Bearer fixture-bedrock-private-key') && $request['tools'][0]['type']==='web_search' && $request['tools'][0]['external_web_access']===false && $request['max_output_tokens']===500)->isNotEmpty(),'Bedrock research sends bounded Mantle request with cached Web Search and private authorization');
 $fakeWeb->responses[]=['status'=>503,'body'=>'Unavailable'];exFail(fn()=>$service->feed($feed),'Failed feeds record a safe failure');$beforeFetches=count($fakeWeb->calls);exFail(fn()=>$service->feed($feed),'Feed backoff prevents immediate network retries');exCheck(count($fakeWeb->calls)===$beforeFetches,'Backoff is enforced before touching the provider');
 $gateway=ConnectorConfig::create(['id'=>(string)Str::uuid(),'name'=>'Fixture outreach gateway','platform'=>'Custom outreach gateway','purpose'=>'outreach','endpoint'=>'https://gateway.example.com/send','enabled'=>true,'settings'=>['channel'=>'custom','auth_header'=>'Authorization','revision'=>'one']]);
 $m=ChannelMessage::create(['id'=>(string)Str::uuid(),'lead_id'=>$lead->id,'connector_id'=>$gateway->id,'recipient'=>'fixture-contact','body'=>'Specific reviewed message','permission_at'=>now()]);$channels=app(ChannelOutbox::class);$stale=$m->digest($gateway);$m->body='Changed draft';$m->save();exFail(fn()=>$channels->approve($m,$stale),'Stale channel-message approvals fail');
 $channels->approve($m,$m->digest($gateway));$fakeWeb->responses[]=['body'=>'{"id":"fixture-receipt"}','headers'=>['content-type'=>'application/json']];$app->instance(PublicWebClient::class,$fakeWeb);$app->instance(ConnectorService::class,new ConnectorService($fakeWeb));$before=count($fakeWeb->calls);
 exCheck($channels->dispatch($m)==='sent' && $channels->dispatch($m)==='sent' && count($fakeWeb->calls)===$before+1,'Approved channel gateways dispatch once; worker retries do not resend');
 $pending=ChannelMessage::create(['id'=>(string)Str::uuid(),'lead_id'=>$lead->id,'connector_id'=>$gateway->id,'recipient'=>'fixture-contact','body'=>'Follow-up','permission_at'=>now(),'status'=>'draft']);
 $envelope=OutboundMessage::create(['id'=>(string)Str::uuid(),'lead_id'=>$lead->id,'sequence'=>9,'recipient'=>'approved@clinic.example.com','from_email'=>'owner@example.test','from_name'=>'Owner','transport'=>'gmail','subject'=>'Approved subject','body'=>'Approved body','status'=>'draft']);
 exCheck(exApi('/api/sales/gmail-envelope',['message_id'=>$envelope->id],false)->getStatusCode()===401,'Gmail gateway rejects missing private authentication');
 exCheck(exApi('/api/sales/gmail-envelope',['message_id'=>$envelope->id])->getStatusCode()===403,'A bearer token cannot send an unapproved draft through Gmail');
 $envelope->refresh();$envelope->status='sending';$envelope->approved_at=now();$envelope->approval_hash=$envelope->digest();$envelope->save();$res=exApi('/api/sales/gmail-envelope',['message_id'=>$envelope->id,'recipient'=>'override@example.test','body'=>'Override']);
 exCheck($res->getStatusCode()===200 && $res->getData(true)['recipient']==='approved@clinic.example.com' && $res->getData(true)['body']==='Approved body','Gmail envelope is canonical server-approved content, not caller-provided text');
 exCheck(exApi('/api/sales/gmail-envelope',['message_id'=>$envelope->id])->getStatusCode()===409,'Each exact approved Gmail reservation can be claimed only once');$envelope->status='cancelled';$envelope->save();
 exCheck(exApi('/api/collection/business-tick',[],false)->getStatusCode()===401,'Automatic business discovery requires private API authentication');
 $email=OutboundMessage::create(['id'=>(string)Str::uuid(),'lead_id'=>$lead->id,'sequence'=>0,'recipient'=>'client@clinic.example.com','from_email'=>'owner@example.test','from_name'=>'Owner','subject'=>'Project','body'=>'Reviewed text','status'=>'sent','sent_at'=>now(),'message_id'=>'known-message@example.test','thread_id'=>'known-thread']);
 $otherEmail = OutboundMessage::create(['id' => (string) Str::uuid(), 'lead_id' => $unknown->id, 'subject' => 'Different lead email', 'body' => 'Fixture']);
 $filteredEmails = exVisit('GET', '/outreach?lead='.$lead->id.'&status=sent')->getContent();
 exCheck(str_contains($filteredEmails, 'id="message-'.$email->id.'"') && !str_contains($filteredEmails, $otherEmail->id), 'Lead and status filters compose in the email queue');
 $inbox=app(InboxProcessor::class);$receipt=['sender'=>'client@clinic.example.com','references'=>['known-message@example.test'],'provider_message_id'=>'reply-fixture-1','subject'=>'Interested in pricing','snippet'=>'We are interested; can you send pricing?'];
 exCheck(!$inbox->record(array_replace($receipt,['sender'=>'other@example.test'])) && InboxEvent::count()===0,'Unrelated inbox messages are neither matched nor stored');
 exCheck($inbox->record($receipt) && $lead->fresh()->reply_intent==='interested' && $lead->fresh()->replied_at!==null && $pending->fresh()->status==='cancelled','Matched replies classify intent, update CRM and stop all pending channels');
 $events=InboxEvent::count();$inbox->record($receipt);exCheck(InboxEvent::count()===$events,'Inbox message IDs make repeated sync idempotent');
 exCheck(InboxProcessor::intent('Please remove me')==='unsubscribe' && InboxProcessor::intent('Out of office')==='out_of_office','Reply rules distinguish opt-out and automatic reply');
 IntegrationSetting::updateOrCreate(['key'=>'mail'],['data'=>['provider'=>'gmail','from_email'=>'owner@example.test','from_name'=>'Owner','gmail_credential_name'=>'Fixture Gmail','sender_verified'=>true]]);IntegrationSetting::updateOrCreate(['key'=>'mailbox_health'],['data'=>['connected'=>true,'sender_matches'=>true]]);$app->instance(ProspectingProfile::class,new ExpandedProfile);
 Http::fake(['*leadgen-gmail-read'=>Http::response(['ok'=>true,'messages'=>[$receipt],'nextPageToken'=>null])]);$sync=app(MailboxSync::class)->sync(true);exCheck($sync['ok'] && $sync['tracked_contacts']===1 && $sync['examined']===1,'Mailbox sync queries only tracked outgoing-client addresses');
 exCheck(exVisit('GET','/inbox')->getStatusCode()===200 && str_contains(exVisit('GET','/inbox')->getContent(),'Interested in pricing'),'Shared reply inbox renders classified matched replies');
 $teamPassword='Ephemeral-team-password-only';$res=exVisit('POST','/team',['name'=>'Viewer fixture','email'=>'viewer@example.test','role'=>'viewer','active'=>1,'password'=>$teamPassword,'password_confirmation'=>$teamPassword,'_token'=>$csrf]);$viewer=OperatorAccount::where('email','viewer@example.test')->first();exCheck($res->getStatusCode()===302 && $viewer && password_verify($teamPassword,$viewer->password_hash),'Owner can create a team account with a hashed password');
 exVisit('POST','/logout',['_token'=>$csrf]);$viewerCsrf=exLogin('viewer@example.test',$teamPassword);exCheck(exVisit('GET','/')->getStatusCode()===200 && exVisit('GET','/settings/automation')->getStatusCode()===403,'Viewer can read leads but cannot access private integration settings');
 $viewerLead = exVisit('GET', '/leads/'.$lead->id)->getContent();
 exCheck(str_contains($viewerLead, 'Notes and tasks') && !str_contains($viewerLead, 'Schedule task') && !str_contains($viewerLead, 'Save country review'), 'Read-only lead screens show details without edit actions');
 exCheck(exVisit('POST','/leads/'.$lead->id.'/notes',['body'=>'Not allowed','_token'=>$viewerCsrf])->getStatusCode()===403,'Viewer cannot mutate CRM records');
 $viewer->role='reviewer';$viewer->save();exCheck(exVisit('POST','/channels/'.$pending->id.'/approve',['approval'=>1,'digest'=>str_repeat('a',64),'_token'=>$viewerCsrf])->getStatusCode()===403,'Reviewers cannot approve sending');
 exCheck(exVisit('POST','/leads/'.$lead->id.'/notes',['body'=>'Reviewer note','_token'=>$viewerCsrf])->getStatusCode()===302,'Reviewer can maintain internal notes');
 $viewer->active=false;$viewer->save();exCheck(exVisit('GET','/')->getStatusCode()===302,'Disabling a team account revokes its live session');
 $csrf=exLogin('owner@example.test','Ephemeral-owner-test-password');
 $changed=$intake->import(['name'=>'Changed domain fixture','website'=>'https://before-change.example.com','country'=>'CA'])['lead'];
 $channel=ChannelMessage::create(['id'=>(string)Str::uuid(),'lead_id'=>$changed->id,'connector_id'=>$gateway->id,'recipient'=>'fixture-contact','body'=>'Previously reviewed text','status'=>'approved','approval_hash'=>str_repeat('a',64),'approved_at'=>now(),'permission_at'=>now()]);
 $res=exVisit('PUT','/leads/'.$changed->id.'/business',['name'=>'Changed domain fixture','website'=>'https://after-change.example.com','_token'=>$csrf]);
 exCheck($res->getStatusCode()===302 && $changed->business->fresh()->geo_status==='unknown' && !$changed->business->fresh()->country_code,'Changing the business domain clears stale country evidence');
 exCheck($channel->fresh()->status==='draft' && !$channel->fresh()->approval_hash,'Changed business identity invalidates pending channel approvals');
 config(['leadgen.sources.facebook-public'=>['permitted'=>true,'kind'=>'search','hosts'=>['www.facebook.com']],'leadgen.sources.remotive'=>['permitted'=>true,'kind'=>'remotive','hosts'=>['remotive.com']]]);
 $beforeQualityGate=Lead::count();
 $badSocial=exApi('/api/leads/import',['source'=>'facebook-public','source_id'=>'bad-crm-recommendation','url'=>'https://www.facebook.com/posts/fixture-crm','title'=>'Looking for a CRM that is easy','description'=>'Any recommendations for an affordable CRM?']);
 exCheck($badSocial->getStatusCode()===422 && Lead::count()===$beforeQualityGate,'Public CRM product recommendations cannot enter the lead inbox');
 $badJob=exApi('/api/leads/import',['source'=>'remotive','source_id'=>'bad-permanent-role','url'=>'https://remotive.com/remote-jobs/fixture-permanent','title'=>'Full-Stack Developer','description'=>'Permanent employee role with health insurance and benefits.']);
 exCheck($badJob->getStatusCode()===422 && Lead::count()===$beforeQualityGate,'Permanent employment roles cannot enter the lead inbox');
 echo "\n$checks expanded-scope checks passed. Isolated fixtures rolled back; HTTP/mail were faked and no public audit network request was made.\n";
}catch(Throwable $e){$failed=true;fwrite(STDERR,$e->getMessage().' at '.$e->getFile().':'.$e->getLine().PHP_EOL);}finally{DB::rollBack();}exit($failed?1:0);

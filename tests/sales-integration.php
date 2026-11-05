<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Http,Artisan};
use Illuminate\Support\Str;
use App\Models\{Lead,IntegrationSetting,IntegrationSecret,OutboundMessage,AiAttempt};
use App\Services\{ProspectingProfile,LeadMatcher,AiDraftService,OutboxService,DeliveryTransport,SalesPipeline};
use App\Services\SmtpConnectionCheck;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
$app=require __DIR__.'/../bootstrap/app.php';$kernel=$app->make(Kernel::class);$kernel->bootstrap();
if(config('app.env')!=='local'||config('database.connections.mysql.port')!=3310||config('database.connections.mysql.host')!=='127.0.0.1')throw new RuntimeException('Dedicated local runtime required');
config(['database.connections.mysql.database'=>'lead_generator_test','cache.stores.file.path'=>storage_path('local-runtime/sales-tests-'.bin2hex(random_bytes(6))),
 'cache.stores.file.lock_path'=>storage_path('local-runtime/sales-test-locks'),'leadgen.import_token'=>bin2hex(random_bytes(32)),
 'leadgen.admin_email'=>'operator@example.test','leadgen.admin_password_hash'=>password_hash('Ephemeral-test-password-only',PASSWORD_DEFAULT)]);
DB::purge('mysql');
$actual=str_replace('\\','/',DB::selectOne('SELECT @@datadir AS path')->path);
if(strcasecmp(rtrim($actual,'/'),str_replace('\\','/',dirname(__DIR__)).'/storage/local-mysql')||DB::getDatabaseName()!=='lead_generator_test')throw new RuntimeException('Wrong test database');
Artisan::call('migrate',['--force'=>true]);DB::beginTransaction();Http::preventStrayRequests();
$checks=0;$cookies=[];$failed=false;
function checkSales(bool $yes,string $text):void {global $checks;if(!$yes)throw new RuntimeException('FAIL: '.$text);echo 'PASS: '.$text.PHP_EOL;$checks++;}
function failSales(callable $fn,string $text):void {try{$fn();}catch(Throwable $e){checkSales(true,$text);return;}throw new RuntimeException('FAIL: '.$text);}
function reqSales(string $method,string $path,array $data=[],bool $api=false){global $kernel,$cookies;$server=['HTTP_HOST'=>'127.0.0.1:8079','REMOTE_ADDR'=>'127.0.0.247','HTTP_ACCEPT'=>$api?'application/json':'text/html'];if($api)$server['HTTP_AUTHORIZATION']='Bearer '.config('leadgen.import_token');$r=Request::create('http://127.0.0.1:8079'.$path,$method,$data,$cookies,[],$server);$res=$kernel->handle($r);foreach($res->headers->getCookies() as $c)$cookies[$c->getName()]=$c->getValue();$kernel->terminate($r,$res);return $res;}
function fixtureSales(string $suffix):Lead{return Lead::create(['source'=>'test-board','source_id'=>'sales-'.$suffix,'url_hash'=>hash('sha256','sales-'.$suffix),'url'=>'https://example.test/project/'.$suffix,'title'=>'Need a Laravel developer','description'=>'A contract Laravel checkout project.','match_score'=>75,'matched_services'=>['Laravel'],'match_reason'=>'Synthetic keyword fixture']);}
class FakeSalesTransport extends DeliveryTransport {public int $count=0;public bool $uncertain=false;public function send(OutboundMessage $m,array $s):array{$this->count++;if($this->uncertain)throw new RuntimeException('TEST_UNCERTAIN');return ['message_id'=>$m->id.'@example.test','thread_id'=>'test-thread-'.$m->id];}}
class FakeSalesProfile extends ProspectingProfile {public function bridges():array{return ['ai_ready'=>true,'gmail_ready'=>false,'inbox_ready'=>false];}}
class ProbeOnlyTransport extends EsmtpTransport {
 public int $starts=0;public int $stops=0;public bool $reject=false;public $duringStart=null;
 public function __construct(){parent::__construct('smtp.example.test',465,true);}
 public function start():void{$this->starts++;if($this->duringStart)($this->duringStart)();if($this->reject)throw new RuntimeException('535 authentication rejected: fixture-private-value');}
 public function stop():void{$this->stops++;}
}
class FakeConnectionCheck extends SmtpConnectionCheck {
 public function __construct(public ProbeOnlyTransport $probe){}
 protected function transport(array $smtp):EsmtpTransport{return $this->probe;}
}
try {
 checkSales(reqSales('GET','/settings/automation')->getStatusCode()===302 && reqSales('GET','/outreach')->getStatusCode()===302,'Settings and approval queue require authentication');
 $login=reqSales('GET','/login');preg_match('/name="_token" value="([^"]+)"/',$login->getContent(),$match);$csrf=html_entity_decode($match[1]);
 reqSales('POST','/login',['email'=>'operator@example.test','password'=>'Ephemeral-test-password-only','_token'=>$csrf]);
 $settingsPage=reqSales('GET','/settings/automation');checkSales($settingsPage->getStatusCode()===200,'Skills, verified facts and both mail connection forms render');
 preg_match('/name="_token" value="([^"]+)"/',$settingsPage->getContent(),$match);$csrf=html_entity_decode($match[1]);
 $app->instance(ProspectingProfile::class,new FakeSalesProfile);$profile=app(ProspectingProfile::class);$lead=fixtureSales('one');
 failSales(fn()=>app(AiDraftService::class)->generate($lead),'AI is blocked without verified business facts and consent');
 $settings=['skills'=>['Laravel'=>['laravel']],'business_name'=>'Synthetic test fixture','verified_facts'=>'Test-only Laravel service; these are not user business claims.','profile_verified'=>true,'ai_consent'=>true,'auto_draft'=>true,'daily_ai_limit'=>10,'minimum_score'=>52,'followup_days'=>3,'max_followups'=>2,'ai_model'=>'test-model'];
 IntegrationSetting::updateOrCreate(['key'=>'pipeline'],['data'=>$settings]);
 checkSales(app(LeadMatcher::class)->score('Laravel project','')['match_score']===52 && app(LeadMatcher::class)->score('Shopify project','')['match_score']===0,'Matching uses the saved skill selection');
 checkSales(str_contains($profile->query('facebook-public',['search_scope'=>'site:facebook.com'],0),'laravel'),'Search queries use saved skills');
 $reply=['score'=>85,'qualified'=>true,'reason'=>'Fixture matches the service','proposal'=>'A synthetic project proposal','email_subject'=>'About your Laravel checkout','email_body'=>'I read your checkout request. Could you share the scope? Reply to opt out.'];
 $aiResult=$reply;$aiHook=null;Http::fake(['*leadgen-ai-draft'=>function()use(&$aiResult,&$aiHook){if($aiHook)$aiHook();return Http::response(['ok'=>true,'result'=>$aiResult]);}]);
 $draft=app(AiDraftService::class)->generate($lead);checkSales($draft?->status==='draft' && $draft->approved_at===null,'Personalized AI results enter the draft queue without sending');
 checkSales(app(AiDraftService::class)->generate($lead)->id===$draft->id && AiAttempt::count()===1,'Repeated draft requests do not spend the budget again');
 checkSales(Http::recorded()->first()[0]['prompt'] && !str_contains(Http::recorded()->first()[0]['prompt'],'smtp_password'),'AI receives project and verified facts without mail credentials');
 $mail=['provider'=>'smtp','from_email'=>'owner@example.test','from_name'=>'Test Owner','sender_verified'=>true];IntegrationSetting::updateOrCreate(['key'=>'mail'],['data'=>$mail]);
 IntegrationSecret::updateOrCreate(['key'=>'smtp'],['data'=>['host'=>'smtp.example.test','password'=>'synthetic-test-only','port'=>587,'username'=>'owner@example.test','security'=>'starttls']]);
 checkSales(!str_contains(DB::table('integration_secrets')->where('key','smtp')->value('data'),'synthetic-test-only') && IntegrationSecret::find('smtp')->data['password']==='synthetic-test-only','Mail password is encrypted at rest and decrypts only inside the app');
 $probeTransport=new ProbeOnlyTransport;$check=new FakeConnectionCheck($probeTransport);$app->instance(SmtpConnectionCheck::class,$check);$probe=$check->check();
 checkSales($probe['authenticated'] && $probe['tls'] && $probe['sender_matches_authenticated_account'] && $probe['emails_sent']===0 && $probeTransport->starts===1 && $probeTransport->stops===1,'SMTP check authenticates and disconnects without sending a message');
 $probeTransport->reject=true;$probe=$check->check();
 checkSales($probe['error_code']==='SMTP_AUTHENTICATION_REJECTED' && !str_contains(json_encode($probe),'fixture-private-value') && !str_contains(json_encode(IntegrationSetting::find('smtp_health')->data),'fixture-private-value'),'SMTP rejection stores only a safe code, never an authentication transcript');
 $probeTransport->reject=false;$probeTransport->duringStart=function()use($mail){IntegrationSetting::find('mail')->update(['data'=>array_replace($mail,['from_name'=>'Changed during probe'])]);};$probe=$check->check();
 checkSales(!$probe['authenticated'] && $probe['error_code']==='CONNECTION_CHANGED_RETEST','Changing settings during a connection check discards the stale success');
 $probeTransport->duringStart=null;IntegrationSetting::find('mail')->update(['data'=>$mail]);
 checkSales(reqSales('POST','/settings/mail/test')->getStatusCode()===419,'SMTP connection checks require CSRF protection');
 $probeTransport->reject=true;$res=reqSales('POST','/settings/mail/test',['_token'=>$csrf]);
 checkSales($res->getStatusCode()===302 && str_contains(reqSales('GET','/settings/automation')->getContent(),'SMTP_AUTHENTICATION_REJECTED'),'Authenticated settings show a useful safe SMTP error');
 $probeTransport->reject=false;
 $transport=new FakeSalesTransport;$app->instance(DeliveryTransport::class,$transport);$outbox=$app->make(OutboxService::class);
 failSales(fn()=>$outbox->approve($draft,$draft->digest($mail)),'Approval requires a verified recipient and permission');
 $res=reqSales('PUT','/leads/'.$lead->id.'/contact',['contact_email'=>'client@example.test','contact_permission'=>1,'_token'=>$csrf]);$lead->refresh();$draft->refresh();
 checkSales($res->getStatusCode()===302 && $draft->recipient==='client@example.test','Verified contact is saved and applied to a pending draft');
 $stale=$draft->digest($mail);$draft->body.=' Edited';$draft->save();failSales(fn()=>$outbox->approve($draft,$stale),'A stale approval digest cannot approve edited text');
 $outbox->approve($draft,$draft->digest($mail));$draft->refresh();checkSales($draft->status==='approved','Exact-message approval is persisted');
 $res=reqSales('PUT','/outreach/'.$draft->id,['revision'=>$draft->revision,'subject'=>$draft->subject,'body'=>'Edited after approval','_token'=>$csrf]);$draft->refresh();
 checkSales($res->getStatusCode()===302 && $draft->status==='draft' && !$draft->approval_hash,'Editing an approved email removes the approval');
 $outbox->approve($draft,$draft->digest($mail));checkSales($outbox->dispatch($draft)==='sent' && $outbox->dispatch($draft)==='sent' && $transport->count===1,'A queued message is sent once; repeated worker calls do not resend');
 $draft->refresh();checkSales($draft->sent_at!==null && $draft->followup_due_at!==null,'Send receipt and follow-up due date persist');
 $child=app(AiDraftService::class)->generate($lead,$draft);checkSales($child->kind==='followup' && $child->status==='draft' && !$child->approval_hash,'Each follow-up is a separate draft requiring fresh approval');
 $bad=reqSales('POST','/api/sales/receipt',['sender'=>'other@example.test','references'=>[$draft->message_id],'outcome'=>'reply'],true);
 checkSales($bad->getData(true)['matched']===false && !$lead->fresh()->replied_at,'Inbox references from an unrelated sender cannot close a lead');
 $good=reqSales('POST','/api/sales/receipt',['sender'=>'client@example.test','references'=>[$draft->message_id],'outcome'=>'reply'],true);
 checkSales($good->getData(true)['matched']===true && $child->fresh()->status==='cancelled','A matching inbox reply records tracking and cancels pending follow-ups');
 failSales(fn()=>$outbox->approve($child->fresh(),$child->fresh()->digest($mail)),'Replied leads cannot be approved for more mail');
 $two=fixtureSales('two');$two->forceFill(['contact_email'=>'second@example.test','contact_permission_at'=>now()])->save();$m=app(AiDraftService::class)->generate($two);$outbox->approve($m,$m->digest($mail));$transport->uncertain=true;
 checkSales($outbox->dispatch($m)==='delivery_unknown','A lost delivery acknowledgement is retained as unknown');$count=$transport->count;$outbox->dispatch($m);
 checkSales($transport->count===$count,'Unknown delivery is never retried automatically');
 $res=reqSales('POST','/outreach/'.$m->id.'/resolve',['resolution'=>'confirmed_not_sent','checked_provider'=>1,'_token'=>$csrf]);checkSales($res->getStatusCode()===302 && $m->fresh()->status==='draft' && !$m->fresh()->approved_at,'Manual confirmation of no delivery returns to draft, requiring fresh approval');
 $three=fixtureSales('three');$three->forceFill(['contact_email'=>'second@example.test','contact_permission_at'=>now()])->save();$outbox->outcome($two,'unsubscribe');
 checkSales($outbox->closed($three) && $m->fresh()->status==='cancelled','Opt-out suppresses the same address across duplicate-source leads');
 $auto=fixtureSales('automatic');$auto->forceFill(['contact_email'=>'automatic@example.test','contact_permission_at'=>now()])->save();$transport->uncertain=false;$summary=app(SalesPipeline::class)->tick();
 checkSales($summary['drafts']===1 && OutboundMessage::where('lead_id',$auto->id)->value('status')==='draft','Automatic worker drafts one matching lead and skips a suppressed address');
 $autoMessage=OutboundMessage::where('lead_id',$auto->id)->first();$outbox->approve($autoMessage,$autoMessage->digest($mail));$mailChanged=$mail;$mailChanged['from_name']='Changed sender';IntegrationSetting::find('mail')->update(['data'=>$mailChanged]);
 checkSales($outbox->dispatch($autoMessage)==='draft','Changing the sending identity invalidates queued approval');IntegrationSetting::find('mail')->update(['data'=>$mail]);
 $smtpForm=['provider'=>'smtp','from_email'=>$mail['from_email'],'from_name'=>$mail['from_name'],'sender_verified'=>1,'smtp_host'=>'smtp.example.test','smtp_port'=>587,'smtp_username'=>$mail['from_email'],'smtp_password'=>'','smtp_security'=>'starttls','_token'=>$csrf];
 $res=reqSales('PUT','/settings/mail',$smtpForm);checkSales($res->getStatusCode()===302 && IntegrationSecret::find('smtp')->data['password']==='synthetic-test-only','Saving SMTP settings with a blank password preserves the encrypted password');
 checkSales(!IntegrationSetting::find('smtp_health'),'Saving connection changes clears the previous SMTP check');
 checkSales(!str_contains(reqSales('GET','/settings/automation')->getContent(),'synthetic-test-only'),'Private mail password never appears in a rendered settings form');
 $four=fixtureSales('four');$aiResult=array_replace($reply,['qualified'=>false]);
 checkSales(app(AiDraftService::class)->generate($four)===null && $four->fresh()->ai_qualified===false,'AI-rejected candidates produce no email draft');
 $five=fixtureSales('five');$aiResult=array_replace($reply,['email_subject'=>"Header\r\nInjected"]);
 failSales(fn()=>app(AiDraftService::class)->generate($five),'Malformed AI email headers are rejected');
 $six=fixtureSales('six');$aiResult=$reply;$aiHook=function()use($six){$six->title='Changed during generation';$six->save();};
 failSales(fn()=>app(AiDraftService::class)->generate($six),'Concurrent listing edits discard an outdated AI result');
 $settings['daily_ai_limit']=1;IntegrationSetting::find('pipeline')->update(['data'=>$settings]);failSales(fn()=>app(AiDraftService::class)->generate(fixtureSales('quota')),'Rolling AI budget counts completed and failed attempts');
 checkSales(reqSales('GET','/outreach')->getStatusCode()===200 && reqSales('GET','/leads/'.$lead->id)->getStatusCode()===200,'Approval queue and lead activity timeline render with saved messages');
 checkSales(reqSales('POST','/outreach/'.$draft->id.'/approve',['approval'=>1,'digest'=>$draft->digest($mail)])->getStatusCode()===419,'Sending approval requires CSRF protection');
 echo "\n$checks sales checks passed. Provider HTTP and mail transport were faked; test data rolled back.\n";
}catch(Throwable $e){$failed=true;fwrite(STDERR,$e->getMessage().' at '.$e->getFile().':'.$e->getLine().PHP_EOL);}finally{DB::rollBack();}exit($failed?1:0);

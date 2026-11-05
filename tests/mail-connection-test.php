<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use App\Models\{Lead,OutboundMessage,IntegrationSetting,InboxEvent,AiAttempt};
use App\Services\{MailConnectionTest,ProspectingProfile,OutboxService,DeliveryTransport,SalesPipeline,MailboxSync,InboxProcessor,CountryPolicy};
use Illuminate\Support\Facades\{DB,Artisan,Http};
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
$app=require __DIR__.'/../bootstrap/app.php';$kernel=$app->make(Kernel::class);$kernel->bootstrap();
if(!app()->environment('local')||config('database.connections.mysql.host')!=='127.0.0.1'||config('database.connections.mysql.port')!=3310)throw new RuntimeException('Dedicated local database only.');
config(['database.connections.mysql.database'=>'lead_generator_test','cache.stores.file.path'=>storage_path('local-runtime/mail-test-'.bin2hex(random_bytes(5))),'cache.stores.file.lock_path'=>storage_path('local-runtime/mail-test-locks'),'leadgen.admin_email'=>'owner@example.test','leadgen.admin_password_hash'=>password_hash('Isolated-fixture-password',PASSWORD_DEFAULT),'leadgen.import_token'=>bin2hex(random_bytes(32))]);
app('cache')->purge('file');DB::purge('mysql');$dataPath=str_replace('\\','/',DB::selectOne('SELECT @@datadir AS path')->path);
if(DB::getDatabaseName()!=='lead_generator_test'||strcasecmp(rtrim($dataPath,'/'),str_replace('\\','/',dirname(__DIR__)).'/storage/local-mysql'))throw new RuntimeException('Unexpected database.');
Artisan::call('migrate',['--force'=>true]);DB::beginTransaction();Http::preventStrayRequests();$checks=0;$cookies=[];$failed=false;$fixtureRemote='127.201.'.random_int(0,255).'.'.random_int(2,250);
function mcCheck(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$message);$checks++;echo 'PASS: '.$message.PHP_EOL;}
function mcFails(callable $run,string $message):void{try{$run();}catch(Throwable $e){mcCheck(true,$message);return;}throw new RuntimeException('FAIL: '.$message);}
function mcVisit(string $method,string $path,array $data=[]){global $kernel,$cookies,$fixtureRemote;$r=Request::create('http://127.0.0.1:8079'.$path,$method,$data,$cookies,[],['HTTP_HOST'=>'127.0.0.1:8079','REMOTE_ADDR'=>$fixtureRemote]);$response=$kernel->handle($r);foreach($response->headers->getCookies() as $c)$cookies[$c->getName()]=$c->getValue();$kernel->terminate($r,$response);return $response;}
function mcCsrf(string $html):string{if(!preg_match('/name="_token" value="([^"]+)"/',$html,$m))throw new RuntimeException('CSRF missing');return html_entity_decode($m[1]);}
class MailTestProfile extends ProspectingProfile {public function bridges():array{return ['gmail_ready'=>true,'inbox_ready'=>true,'ai_ready'=>false];}}
class MailTestTransport extends DeliveryTransport {public int $sends=0;public function send(OutboundMessage $m,array $settings):array{$this->sends++;return ['message_id'=>$m->id.'@example.test','thread_id'=>'fixture-thread-'.$m->id];}}
try{
 IntegrationSetting::updateOrCreate(['key'=>'business_discovery'],['data'=>['excluded_countries'=>['IN','PK'],'require_country_review'=>true]]);
 IntegrationSetting::updateOrCreate(['key'=>'pipeline'],['data'=>['auto_draft'=>false,'followup_days'=>3,'max_followups'=>2]]);
 $sender=['provider'=>'gmail','sender_verified'=>true,'from_email'=>'sender@example.test','from_name'=>'Fixture owner','gmail_credential_name'=>'Fixture Gmail'];
 IntegrationSetting::updateOrCreate(['key'=>'mail'],['data'=>$sender]);IntegrationSetting::whereKey('mail_connection_test')->delete();
 IntegrationSetting::updateOrCreate(['key'=>'mailbox_health'],['data'=>['connected'=>true,'sender_matches'=>true,'read_access'=>true]]);
 $profile=new MailTestProfile;$transport=new MailTestTransport;$app->instance(ProspectingProfile::class,$profile);$app->instance(DeliveryTransport::class,$transport);
 $tests=app(MailConnectionTest::class);$outbox=app(OutboxService::class);
 mcCheck(mcVisit('GET','/settings/mail-test')->getStatusCode()===302,'Technical test page requires authentication');
 $csrf=mcCsrf(mcVisit('GET','/login')->getContent());mcVisit('POST','/login',['email'=>'owner@example.test','password'=>'Isolated-fixture-password','_token'=>$csrf]);
 $csrf=mcCsrf(mcVisit('GET','/settings/mail-test')->getContent());
 mcCheck(mcVisit('POST','/settings/mail-test',['recipient'=>'owned@example.test','ownership'=>1])->getStatusCode()===419,'Preparing a technical test requires CSRF');
 mcCheck(mcVisit('POST','/settings/mail-test',['recipient'=>'owned@example.test','_token'=>$csrf])->getStatusCode()===302 && !Lead::where('kind',MailConnectionTest::KIND)->exists(),'Recipient ownership confirmation is required');
 mcFails(fn()=>$tests->prepare('sender@example.test'),'Sending inbox cannot be used as its own reply-test recipient');
 mcCheck(mcVisit('POST','/settings/mail-test',['recipient'=>'owned@example.test','ownership'=>1,'_token'=>$csrf])->getStatusCode()===302,'Owner can prepare the fixed draft through the private form');
 $lead=Lead::findOrFail($tests->state()['lead_id']);$initial=OutboundMessage::where('lead_id',$lead->id)->firstOrFail();
 mcCheck($initial->status==='draft' && $transport->sends===0,'Preparing a test does not approve or send mail');
 mcCheck(!$lead->country_code&&!$lead->country_reviewed_at&&!app(CountryPolicy::class)->allowed($lead),'Technical test does not invent a country or alter prospect country policy');
 mcCheck($tests->allowed($lead)&&!$outbox->closed($lead),'Only the registered owned-inbox technical test has its temporary fixed-message permit');
 mcCheck($tests->prepare('owned@example.test')->id===$lead->id && OutboundMessage::where('lead_id',$lead->id)->count()===1,'Repeated prepare is idempotent');
 mcFails(fn()=>$tests->prepare('other@example.test'),'An active test cannot be redirected to another inbox');
 mcCheck($outbox->dispatch($initial)==='draft'&&$transport->sends===0,'The normal dispatcher refuses an unapproved test draft');
 $spec=$tests->spec($tests->state(),0);$initial->subject='Different sales content';$initial->save();
 mcFails(fn()=>$outbox->approve($initial,$initial->digest($sender)),'Exact digest cannot authorize promotional content through technical mode');
 $initial->subject=$spec['subject'];$initial->recipient='other@example.test';$initial->save();
 mcFails(fn()=>$outbox->approve($initial,$initial->digest($sender)),'A changed recipient cannot use the registered test permit');
 $initial->recipient=$lead->contact_email;$initial->save();
 $outbox->approve($initial,$initial->digest($sender));$initial->refresh();$initial->body='Changed after approval';$initial->save();
 mcCheck($outbox->dispatch($initial)==='draft'&&$transport->sends===0,'Dispatch revalidates fixed content and invalidates a changed approved test');
 $initial->body=$spec['body'];$initial->save();$outbox->approve($initial,$initial->digest($sender));
 mcCheck($outbox->dispatch($initial)==='sent'&&$outbox->dispatch($initial)==='sent'&&$transport->sends===1,'Approved initial test is sent once through the normal dispatcher');
 $initial->refresh();mcCheck($initial->sent_at!==null&&$initial->followup_due_at->diffInSeconds($initial->sent_at,true)===120.0,'Only the technical test uses a two-minute follow-up due time');
 $now=now()->copy();\Carbon\Carbon::setTestNow($now->copy()->addMinutes(3));
 $mailboxRows=[];Http::fake(['*leadgen-gmail-read'=>function()use(&$mailboxRows){return Http::response(['ok'=>true,'messages'=>$mailboxRows,'nextPageToken'=>null]);}]);
 $tick=app(SalesPipeline::class)->tick();$followup=OutboundMessage::where('lead_id',$lead->id)->where('sequence',1)->first();
 mcCheck($tick['followups']===1&&$followup&&$followup->status==='draft'&&!$followup->approval_hash,'Normal worker creates a due fixed follow-up as an unapproved draft');
 mcCheck($transport->sends===1&&AiAttempt::count()===0,'Technical follow-up uses no LLM and sends no unapproved message');
 $tick=app(SalesPipeline::class)->tick();mcCheck($tick['followups']===0&&OutboundMessage::where('lead_id',$lead->id)->count()===2,'Repeated worker ticks cannot duplicate the test follow-up');
 $receipt=['sender'=>'owned@example.test','references'=>[$initial->message_id,$initial->thread_id],'provider_message_id'=>'fixture-reply-1','subject'=>'Re: '.$initial->subject,'snippet'=>'LGTEST fixture reply','outcome'=>'reply'];
 mcCheck(!app(InboxProcessor::class)->record(array_replace($receipt,['sender'=>'unrelated@example.test'])),'Unrelated inbox sender cannot complete the technical test');
 $mailboxRows=[$receipt];
 $sync=app(MailboxSync::class)->sync(true);
 mcCheck($sync['ok']&&$sync['matched']===1&&$lead->fresh()->replied_at!==null,'Normal Gmail mailbox sync matches the test thread and records the reply');
 mcCheck($followup->fresh()->status==='cancelled'&&!$initial->fresh()->followup_due_at,'Reply cancels the pending follow-up and clears its due date');
 mcFails(fn()=>$outbox->approve($followup->fresh(),$followup->fresh()->digest($sender)),'Reply prevents approval of further technical-test mail');
 $events=InboxEvent::count();app(MailboxSync::class)->sync(true);mcCheck(InboxEvent::count()===$events,'Repeated reply sync is idempotent');
 $dashboard=app(\App\Http\Controllers\DashboardController::class)->index(Request::create('/'))->getData();
 mcCheck($dashboard['stats']['total']===0&&$dashboard['overview']['all_records']===0&&$dashboard['pipeline']['sent']===0,'Technical records and sends do not inflate lead or sales dashboard metrics');
 $reports=app(\App\Http\Controllers\CrmController::class)->reports()->getData();mcCheck($reports['total']===0&&$reports['replyIntents']->isEmpty(),'Technical replies remain separate from sales conversion reports');
 mcCheck(mcVisit('GET','/settings/mail-test')->getStatusCode()===200&&str_contains(mcVisit('GET','/settings/mail-test')->getContent(),'pending follow-ups stopped'),'Private test page displays the saved reply and follow-up state');
 mcCheck(mcVisit('PUT','/outreach/'.$followup->id,['subject'=>'Sales','body'=>'Sales','revision'=>1,'_token'=>$csrf])->getStatusCode()===409,'Private UI cannot edit a fixed technical test into sales content');
 mcCheck(mcVisit('GET','/leads/'.$lead->id)->getStatusCode()===302,'Technical record opens its test page, not a prospect editing form');
 $oldMail=$sender;$sender['from_name']='Different sender';IntegrationSetting::find('mail')->update(['data'=>$sender]);mcCheck(!$tests->allowed($lead),'Changing sender identity invalidates the test permit');IntegrationSetting::find('mail')->update(['data'=>$oldMail]);
 \Carbon\Carbon::setTestNow($now->copy()->addHours(3));mcCheck(!$tests->allowed($lead),'Technical test permit expires without weakening country restrictions');
 mcCheck(IntegrationSetting::find('business_discovery')->data['excluded_countries']===['IN','PK'],'Excluded-country configuration remains unchanged');
 echo "\n$checks technical mail-flow checks passed. HTTP/mail faked; isolated fixtures rolled back; no real email sent.\n";
}catch(Throwable $e){$failed=true;fwrite(STDERR,$e->getMessage().' at '.$e->getFile().':'.$e->getLine().PHP_EOL);}finally{\Carbon\Carbon::setTestNow();DB::rollBack();}exit($failed?1:0);

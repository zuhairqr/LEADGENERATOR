<?php
// Finish an owner-authorized local setup after normal Google browser consent.
// This verifies account/read permissions; it never sends an email.
require __DIR__.'/../vendor/autoload.php';
use App\Models\{IntegrationSetting,OutboundMessage};
use App\Services\{MailboxSync,ProspectingProfile};
use Illuminate\Support\Facades\DB;
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!in_array('--connect',$argv,true)||!app()->environment('local')||config('database.connections.mysql.host')!=='127.0.0.1'||config('database.connections.mysql.port')!=3310||config('database.connections.mysql.database')!=='lead_generator_local')throw new RuntimeException('Explicit local Gmail connection setup required.');
$profile=app(ProspectingProfile::class);$before=$profile->sender();$health=app(MailboxSync::class)->verify();
if(empty($health['connected'])||empty($health['sender_matches'])||empty($profile->bridges()['gmail_ready'])){echo json_encode(['connected'=>false,'error_code'=>'GMAIL_AUTHORIZATION_SCOPE_OR_SENDER_REQUIRED','emails_sent'=>0]).PHP_EOL;exit(1);}
$connected=DB::transaction(function()use($profile,$before){
 $setting=IntegrationSetting::whereKey('mail')->lockForUpdate()->firstOrFail();$mail=$setting->data;
 if(($mail['from_email']??'')!==($before['from_email']??'')||($mail['gmail_credential_name']??'')!==($before['gmail_credential_name']??''))return false;
 $mail['provider']='gmail';$mail['sender_verified']=true;$setting->data=$mail;$setting->save();
 OutboundMessage::where('status','approved')->update(['status'=>'draft','approval_hash'=>null,'approved_at'=>null,'error_code'=>'SENDER_CHANGED_REAPPROVE']);return true;
},3);
$sync=$connected?app(MailboxSync::class)->sync(true):['ok'=>false,'error_code'=>'SENDER_CHANGED_DURING_CHECK'];
$result=['connected'=>$connected,'sender_verified'=>$connected,'reply_sync_ok'=>!empty($sync['ok']),'matched_replies'=>$sync['matched']??0,'tracked_contacts'=>$sync['tracked_contacts']??0,'emails_sent'=>0,'checked_at'=>gmdate('c')];
if(!$connected||empty($sync['ok']))$result['error_code']=$sync['error_code']??'GMAIL_SYNC_REQUIRES_ATTENTION';
file_put_contents(storage_path('n8n/logs/gmail-connection-result.json'),json_encode($result,JSON_PRETTY_PRINT));echo json_encode($result).PHP_EOL;exit($connected&&!empty($sync['ok'])?0:1);

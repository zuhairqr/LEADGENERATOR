<?php
namespace App\Services;
use App\Models\{IntegrationSetting,OutboundMessage};
use Illuminate\Support\Facades\{Http,Cache};
class MailboxSync {
 private function request(string $path,array $body):array {
  $r=Http::withToken(config('leadgen.import_token'))->acceptJson()->connectTimeout(5)->timeout(120)->post(rtrim(config('leadgen.sales_bridge'),'/').'/'.$path,$body);
  if(!$r->successful()||$r->json('ok')!==true)throw new \RuntimeException('GMAIL_AUTHORIZATION_OR_SCOPE_REQUIRED');return $r->json();
 }
 public function verify():array {
  try{$r=$this->request('leadgen-gmail-profile',[]);$sender=app(ProspectingProfile::class)->sender();$matched=!empty($sender['from_email'])&&strcasecmp($sender['from_email'],$r['emailAddress']??'')===0;
   $status=['connected'=>!empty($r['read_access']),'read_access'=>!empty($r['read_access']),'sender_matches'=>$matched,'checked_at'=>now()->toIso8601String(),'error_code'=>$matched?null:'GMAIL_SENDER_MISMATCH'];
  }catch(\Throwable $e){$status=['connected'=>false,'sender_matches'=>false,'checked_at'=>now()->toIso8601String(),'error_code'=>'GMAIL_AUTHORIZATION_OR_SCOPE_REQUIRED'];}
  $status['credential_name']=app(ProspectingProfile::class)->sender()['gmail_credential_name']??null;IntegrationSetting::updateOrCreate(['key'=>'mailbox_health'],['data'=>$status]);return $status;
 }
 public function sync(bool $force=false):array {
  $profile=app(ProspectingProfile::class);if(!($profile->bridges()['inbox_ready']??false))return ['ok'=>false,'error_code'=>'INBOX_NOT_CONNECTED'];
  $health=IntegrationSetting::find('mailbox_health')?->data??[];if(empty($health['connected'])||empty($health['sender_matches']))return ['ok'=>false,'error_code'=>'GMAIL_AUTHORIZATION_REQUIRED'];
  $lock=Cache::lock('mailbox-sync',150);if(!$lock->get())return ['ok'=>false,'error_code'=>'INBOX_SYNC_BUSY'];
  try{
   $state=IntegrationSetting::find('mailbox_sync')?->data??[];if(!$force&&!empty($state['checked_at'])&&\Carbon\Carbon::parse($state['checked_at'])->gt(now()->subMinutes(2)))return $state;
   if(!$force&&!empty($state['retry_after'])&&\Carbon\Carbon::parse($state['retry_after'])->isFuture())return $state;
   $sender=$profile->sender();$contacts=OutboundMessage::where(fn($q)=>$q->whereIn('status',['sent','delivery_unknown'])->orWhereIn('error_code',['MANUALLY_CONFIRMED_SENT','MANUALLY_CONFIRMED_NOT_SENT']))->where(fn($q)=>$q->whereNotNull('message_id')->orWhereNotNull('thread_id'))->where('from_email',$sender['from_email']??'')->whereNotNull('recipient')->where('created_at','>=',now()->subDays(90))->distinct()->orderBy('recipient')->pluck('recipient')->all();
   if(!$contacts){$result=['ok'=>true,'matched'=>0,'examined'=>0,'tracked_contacts'=>0,'checked_at'=>now()->toIso8601String()];IntegrationSetting::updateOrCreate(['key'=>'mailbox_sync'],['data'=>$result]);return $result;}
   $batches=array_chunk($contacts,20);$index=(int)($state['batch_index']??0)%count($batches);$batch=$batches[$index];$key='ib_'.substr(hash('sha256',implode('|',$batch)),0,32);$cursor=IntegrationSetting::find($key)?->data??[];
   $r=$this->request('leadgen-gmail-read',['fromEmail'=>$sender['from_email'],'contacts'=>$batch,'after'=>$cursor['after']??now()->subDays(30)->timestamp,'pageToken'=>$cursor['page_token']??null]);
   $matched=0;foreach($r['messages']??[] as $message){if(!is_array($message)||empty($message['sender'])||empty($message['references']))continue;if(app(InboxProcessor::class)->record($message))$matched++;}
   $started=$cursor['started_at']??now()->timestamp;$next=$r['nextPageToken']??null;IntegrationSetting::updateOrCreate(['key'=>$key],['data'=>['after'=>$next?($cursor['after']??now()->subDays(30)->timestamp):max(0,$started-300),'page_token'=>$next,'started_at'=>$next?$started:null]]);
   $result=['ok'=>true,'matched'=>$matched,'examined'=>count($r['messages']??[]),'tracked_contacts'=>count($contacts),'batch_index'=>$next?$index:($index+1)%count($batches),'checked_at'=>now()->toIso8601String()];IntegrationSetting::updateOrCreate(['key'=>'mailbox_sync'],['data'=>$result]);return $result;
  }catch(\Throwable $e){$failures=(int)($state['failure_count']??0)+1;$result=['ok'=>false,'error_code'=>'GMAIL_SYNC_FAILED_REAUTHORIZE_OR_CHECK_SCOPES','failure_count'=>$failures,'retry_after'=>now()->addMinutes(min(60,2**min(6,$failures)))->toIso8601String(),'checked_at'=>now()->toIso8601String()];IntegrationSetting::updateOrCreate(['key'=>'mailbox_sync'],['data'=>$result]);return $result;}
  finally{$lock->release();}
 }
}

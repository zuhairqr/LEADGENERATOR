<?php
namespace App\Services;
use App\Models\{Lead,OutboundMessage,IntegrationSetting,OutreachEvent};
use Illuminate\Support\Facades\{Cache,DB};
use Illuminate\Support\Str;

/** Fixed, non-promotional self-test. No prospect country or source approval is granted. */
class MailConnectionTest
{
 public const KIND='connection_test';
 public function state():array{return IntegrationSetting::find('mail_connection_test')?->data??[];}
 public function spec(array $state,int $sequence):array {
  $marker='LGTEST-'.substr($state['id'],0,8);
  $subject='[Lead Generator '.$marker.'] Gmail reply tracking test';
  return ['subject'=>($sequence?'Re: ':'').$subject,'body'=>$sequence
   ?"This is the fixed follow-up for your requested Lead Generator connection test.\n\nReply to the original test email with $marker to check reply matching and follow-up cancellation.\n\nThis is a technical self-test, not a sales proposal. No other follow-up will be generated."
   :"This is the Lead Generator connection test you requested for your own inbox.\n\nWait for the follow-up draft to appear in the app, then reply to this email in the same thread with $marker. This checks Gmail delivery, reply matching and cancellation of the pending follow-up.\n\nThe follow-up remains a draft unless separately approved. This is a technical self-test, not a sales proposal."];
 }
 public function allowed(Lead $lead):bool {
  $s=$this->state();$sender=app(ProspectingProfile::class)->sender();
  try{$valid=isset($s['expires_at'])&&\Carbon\Carbon::parse($s['expires_at'])->isFuture();}catch(\Throwable $e){$valid=false;}
  return $lead->kind===self::KIND && $lead->source==='system-connection-test' && (int)($s['lead_id']??0)===$lead->id
   && !empty($s['ownership_confirmed']) && $valid && ($s['id']??'')===$lead->source_id
   && ($s['recipient']??'')===$lead->contact_email && ($s['sender_email']??'')===($sender['from_email']??'')
   && ($s['sender_name']??'')===($sender['from_name']??'') && ($s['credential_name']??'')===($sender['gmail_credential_name']??'')
   && $lead->contact_permission_at && ($sender['provider']??'')==='gmail' && !empty($sender['sender_verified']);
 }
 public function validMessage(OutboundMessage $message):bool {
  $lead=$message->lead;if(!$lead||!$this->allowed($lead)||!in_array((int)$message->sequence,[0,1],true))return false;
  $s=$this->state();$spec=$this->spec($s,(int)$message->sequence);
  if($message->recipient!==$s['recipient']||$message->subject!==$spec['subject']||$message->body!==$spec['body'])return false;
  if($message->sequence===0)return !$message->parent_id;
  $parent=OutboundMessage::find($message->parent_id);
  return $parent && $parent->lead_id===$lead->id && $parent->sequence===0 && $parent->status==='sent';
 }
 public function prepare(string $recipient):Lead {
  $recipient=mb_strtolower(trim($recipient));$profile=app(ProspectingProfile::class);$sender=$profile->sender();
  if(!filter_var($recipient,FILTER_VALIDATE_EMAIL)||strcasecmp($recipient,$sender['from_email']??'')===0)throw new \RuntimeException('SECOND_OWNED_INBOX_REQUIRED');
  if(($sender['provider']??'')!=='gmail'||empty($sender['sender_verified'])||empty($profile->bridges()['gmail_ready']))throw new \RuntimeException('VERIFIED_GMAIL_REQUIRED');
  $lock=Cache::lock('prepare-mail-connection-test',15);if(!$lock->get())throw new \RuntimeException('TEST_PREPARATION_BUSY');
  try{return DB::transaction(function()use($recipient,$sender){
   $old=$this->state();
   if(!empty($old['lead_id'])&&($lead=Lead::find($old['lead_id']))&&$this->allowed($lead)&&!$lead->replied_at){if($lead->contact_email===$recipient)return $lead;throw new \RuntimeException('ANOTHER_CONNECTION_TEST_ACTIVE');}
   if(Lead::where('kind',self::KIND)->where('created_at','>=',now()->subDay())->count()>=2)throw new \RuntimeException('CONNECTION_TEST_DAILY_LIMIT');
   $id=(string)Str::uuid();
   $lead=new Lead(['source'=>'system-connection-test','source_id'=>$id,'url_hash'=>hash('sha256','technical-test:'.$id),'url'=>route('mail-test.index'),'title'=>'[TECHNICAL TEST] Gmail reply tracking','description'=>'Owner-requested technical self-test. Not a business prospect, buyer request or country claim.','match_score'=>0,'matched_services'=>[],'match_reason'=>'Excluded from prospect metrics.']);
   $lead->kind=self::KIND;$lead->ai_state='not_applicable';$lead->contact_email=$recipient;$lead->contact_permission_at=now();$lead->save();
   $state=['id'=>$id,'lead_id'=>$lead->id,'recipient'=>$recipient,'sender_email'=>$sender['from_email'],'sender_name'=>$sender['from_name']??'','credential_name'=>$sender['gmail_credential_name']??'','ownership_confirmed'=>true,'created_at'=>now()->toIso8601String(),'expires_at'=>now()->addHours(2)->toIso8601String()];
   IntegrationSetting::updateOrCreate(['key'=>'mail_connection_test'],['data'=>$state]);$spec=$this->spec($state,0);
   OutboundMessage::create(['id'=>(string)Str::uuid(),'lead_id'=>$lead->id,'sequence'=>0,'kind'=>'initial','recipient'=>$recipient,'subject'=>$spec['subject'],'body'=>$spec['body'],'status'=>'draft']);
   OutreachEvent::create(['lead_id'=>$lead->id,'event'=>'connection_test_prepared']);return $lead;
  });}finally{$lock->release();}
 }
 public function draftDue():int {
  $s=$this->state();$lead=isset($s['lead_id'])?Lead::find($s['lead_id']):null;if(!$lead||!$this->allowed($lead))return 0;
  return DB::transaction(function()use($lead,$s){
   $fresh=Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
   if(!$this->allowed($fresh)||$fresh->replied_at||$fresh->bounced_at||$fresh->unsubscribed_at||in_array($fresh->status,['won','lost','rejected'],true))return 0;
   $parent=OutboundMessage::where('lead_id',$fresh->id)->where('sequence',0)->where('status','sent')->whereNotNull('followup_due_at')->where('followup_due_at','<=',now())->lockForUpdate()->first();
   if(!$parent||OutboundMessage::where('lead_id',$fresh->id)->where('sequence',1)->exists())return 0;
   $spec=$this->spec($s,1);$m=OutboundMessage::create(['id'=>(string)Str::uuid(),'lead_id'=>$fresh->id,'parent_id'=>$parent->id,'sequence'=>1,'kind'=>'followup','recipient'=>$fresh->contact_email,'subject'=>$spec['subject'],'body'=>$spec['body'],'status'=>'draft']);
   $parent->update(['followup_due_at'=>null]);OutreachEvent::create(['lead_id'=>$fresh->id,'message_id'=>$m->id,'event'=>'connection_test_followup_drafted']);return 1;
  },3);
 }
}

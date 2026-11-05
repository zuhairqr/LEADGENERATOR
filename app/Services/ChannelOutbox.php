<?php
namespace App\Services;
use App\Models\{ChannelMessage,ConnectorConfig,Lead,OutreachEvent};
use Illuminate\Support\Facades\{Cache,DB};
class ChannelOutbox {
 public function approve(ChannelMessage $message,string $digest):void{
  DB::transaction(function()use($message,$digest){$lead=Lead::whereKey($message->lead_id)->lockForUpdate()->firstOrFail();$m=ChannelMessage::whereKey($message->id)->lockForUpdate()->firstOrFail();$c=ConnectorConfig::findOrFail($m->connector_id);
   if($m->status!=='draft'||!$m->permission_at||!$c->enabled||$c->purpose!=='outreach'||app(OutboxService::class)->closed($lead)||!hash_equals($m->digest($c),$digest))throw new \RuntimeException('CHANNEL_APPROVAL_REQUIRES_REVIEW');
   $m->update(['status'=>'approved','approved_at'=>now(),'approval_hash'=>$m->digest($c)]);OutreachEvent::create(['lead_id'=>$lead->id,'event'=>'channel_message_approved','details'=>['channel'=>$c->settings['channel']??'custom']]);
  },3);
 }
 public function dispatch(ChannelMessage $message):string {
  $lock=Cache::lock('channel-send:'.$message->id,60);if(!$lock->get())return 'busy';
  try{[$m,$c,$reserved]=DB::transaction(function()use($message){$lead=Lead::whereKey($message->lead_id)->lockForUpdate()->firstOrFail();$m=ChannelMessage::whereKey($message->id)->lockForUpdate()->firstOrFail();$c=ConnectorConfig::findOrFail($m->connector_id);if($m->status!=='approved')return [$m,$c,false];if(app(OutboxService::class)->closed($lead)||!$m->permission_at){$m->update(['status'=>'cancelled']);return [$m,$c,false];}if(!$c->enabled||!hash_equals($m->digest($c),$m->approval_hash??'')){$m->update(['status'=>'draft','approval_hash'=>null,'approved_at'=>null]);return [$m,$c,false];}$m->update(['status'=>'sending']);return [$m,$c,true];},3);if(!$reserved)return $m->status;
   try{$r=app(PublicWebClient::class)->fetch($c->endpoint,262144,array_merge(app(ConnectorService::class)->headers($c),['Content-Type: application/json','Idempotency-Key: '.$m->id]),'POST',json_encode(['event'=>'approved.outreach','message_id'=>$m->id,'channel'=>$c->settings['channel']??'custom','recipient'=>$m->recipient,'body'=>$m->body,'parent_id'=>$m->parent_id]));if($r['status']<200||$r['status']>=300)throw new \RuntimeException();$data=json_decode($r['body'],true)?:[];$m->update(['status'=>'sent','sent_at'=>now(),'receipt'=>mb_substr((string)($data['id']??$data['message_id']??('HTTP '.$r['status'])),0,255),'followup_due_at'=>now()->addDays((int)(app(ProspectingProfile::class)->data()['followup_days']??3))]);OutreachEvent::create(['lead_id'=>$m->lead_id,'event'=>'channel_provider_accepted']);return 'sent';}
   catch(\Throwable $e){$m->update(['status'=>'delivery_unknown','error_code'=>'CHANNEL_ACKNOWLEDGEMENT_MISSING']);return 'delivery_unknown';}
  }finally{$lock->release();}
 }
}

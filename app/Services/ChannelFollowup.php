<?php
namespace App\Services;
use App\Models\{ChannelMessage,ConnectorConfig,OutboundMessage,AiAttempt,Lead};
use Illuminate\Support\Facades\{Cache,DB};
use Illuminate\Support\Str;
class ChannelFollowup {
 public function draft(ChannelMessage $parent):?ChannelMessage {
  $profile=app(ProspectingProfile::class);$c=ConnectorConfig::findOrFail($parent->connector_id);$lead=$parent->lead;
  if(!$profile->aiReady()||!$c->enabled||$parent->status!=='sent'||app(OutboxService::class)->closed($lead)||$parent->sequence>=($profile->data()['max_followups']??2))throw new \RuntimeException('CHANNEL_FOLLOWUP_NOT_ALLOWED');
  $lock=Cache::lock('channel-followup:'.$parent->id,120);if(!$lock->get())throw new \RuntimeException('DRAFT_BUSY');$attempt=null;
  try{
   if($existing=ChannelMessage::where('parent_id',$parent->id)->first())return $existing;$budget=Cache::lock('sales-ai-budget',10);if(!$budget->get())throw new \RuntimeException('AI_BUDGET_BUSY');
   try{if(AiAttempt::where('created_at','>=',now()->subDay())->count()>=($profile->data()['daily_ai_limit']??5))throw new \RuntimeException('AI_DAILY_LIMIT');$attempt=AiAttempt::create(['id'=>(string)Str::uuid(),'lead_id'=>$lead->id,'kind'=>'channel_followup','status'=>'running']);}finally{$budget->release();}
   $version=hash('sha256',json_encode([$lead->updated_at,$lead->business?->updated_at,$lead->audits()->max('id'),$parent->body,$c->settings,$profile->data()]));
   $result=app(ProposalWriter::class)->draft($lead,new OutboundMessage(['body'=>$parent->body]),$c->settings['channel']??'custom');
   return DB::transaction(function()use($lead,$parent,$c,$profile,$version,$result,$attempt){$fresh=Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();$gateway=$c->fresh();if(app(OutboxService::class)->closed($fresh)||!$gateway->enabled||$version!==hash('sha256',json_encode([$fresh->updated_at,$fresh->business?->updated_at,$fresh->audits()->max('id'),$parent->fresh()->body,$gateway->settings,$profile->data()])))throw new \RuntimeException('CONTEXT_CHANGED');
    $attempt->update(['status'=>'completed']);if(!$result['qualified']){$parent->update(['followup_due_at'=>null]);return null;}
    return ChannelMessage::create(['id'=>(string)Str::uuid(),'lead_id'=>$fresh->id,'connector_id'=>$c->id,'recipient'=>$parent->recipient,'body'=>$result['email_body'],'status'=>'draft','parent_id'=>$parent->id,'sequence'=>$parent->sequence+1,'permission_at'=>$parent->permission_at]);
   },3);
  }catch(\Throwable $e){if($attempt){$attempt->update(['status'=>'failed','error_code'=>'CHANNEL_DRAFT_FAILED']);$parent->update(['error_code'=>'FOLLOWUP_DRAFT_FAILED']);}throw new \RuntimeException('CHANNEL_DRAFT_FAILED');}finally{$lock->release();}
 }
}

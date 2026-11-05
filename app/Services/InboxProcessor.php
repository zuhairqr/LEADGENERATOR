<?php
namespace App\Services;
use App\Models\{OutboundMessage,InboxEvent,OutreachEvent};
use Illuminate\Support\Facades\DB;
class InboxProcessor {
 public static function intent(string $text,string $outcome='reply'):string {
  if($outcome==='unsubscribe'||preg_match('/\b(?:unsubscribe|remove me|stop emailing|do not contact|don.t contact)\b/i',$text))return 'unsubscribe';
  if($outcome==='bounce')return 'bounce';if(preg_match('/\b(?:out of (?:the )?office|automatic reply|auto.?reply|on vacation)\b/i',$text))return 'out_of_office';
  if(preg_match('/\b(?:not interested|no thank you|no thanks|not looking)\b/i',$text))return 'not_interested';
  if(preg_match('/\b(?:interested|schedule|quotation|pricing|let.s talk|book a call)\b/i',$text))return 'interested';if(str_contains($text,'?'))return 'question';return 'reply';
 }
 public function record(array $data,string $provider='gmail'):bool {
  $sender=strtolower($data['sender']);$refs=array_map(fn($x)=>trim($x,'<> '),$data['references']);$target=($data['outcome']??'reply')==='bounce'?strtolower($data['failed_recipient']??$sender):$sender;
  $m=OutboundMessage::where('recipient',$target)->where(function($q)use($refs){$q->whereIn('message_id',$refs)->orWhereIn('thread_id',$refs);})->latest()->first();if(!$m)return false;
  $key=hash('sha256',$provider.'|'.($data['provider_message_id']??json_encode([$sender,$refs,$data['outcome']??'reply'])));
  return DB::transaction(function()use($m,$key,$data,$provider,$sender){
   \App\Models\Lead::whereKey($m->lead_id)->lockForUpdate()->firstOrFail();
   if(InboxEvent::where('event_key',$key)->exists())return true;
   $subject=mb_substr($data['subject']??'',0,255);$excerpt=mb_substr($data['snippet']??'',0,1200);$intent=self::intent($subject.' '.$excerpt,$data['outcome']??'reply');
   InboxEvent::create(['event_key'=>$key,'lead_id'=>$m->lead_id,'outbound_message_id'=>$m->id,'provider'=>$provider,'provider_message_id'=>$data['provider_message_id']??$key,'sender'=>$sender,'subject'=>$subject,'intent'=>$intent,'classification_method'=>'keyword_rules_reviewable','excerpt'=>$excerpt]);
   app(OutboxService::class)->outcome($m->lead,$intent==='unsubscribe'?'unsubscribe':($intent==='bounce'?'bounce':'reply'),'authenticated_inbox');
   $lead=$m->lead->fresh();$lead->reply_intent=$intent;$lead->save();
   if ($lead->kind !== MailConnectionTest::KIND && in_array($intent, ['interested', 'question', 'out_of_office'], true)) {
       $lead->tasks()->create(['title' => 'Review '.str_replace('_', ' ', $intent).' inbox reply', 'due_at' => now()->addDay(), 'author' => 'Inbox tracking']);
       $lead->refreshNextAction();
   }
   OutreachEvent::create(['lead_id'=>$lead->id,'event'=>'reply_classified','details'=>['intent'=>$intent,'method'=>'keyword_rules_reviewable']]);return true;
  },3);
 }
}

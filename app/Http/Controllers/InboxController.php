<?php
namespace App\Http\Controllers;
use App\Models\{InboxEvent,IntegrationSetting};
use App\Services\MailboxSync;
use Illuminate\Http\Request;
class InboxController {
 public function index(){return view('inbox',['events'=>InboxEvent::latest()->paginate(30),'health'=>IntegrationSetting::find('mailbox_health')?->data??[],'sync'=>IntegrationSetting::find('mailbox_sync')?->data??[]]);}
 public function sync(MailboxSync $sync){$r=$sync->sync(true);return back()->with('notice',$r['ok']?'Inbox checked. Matched '.($r['matched']??0).' replies; no email sent.':'Inbox sync unavailable: '.($r['error_code']??'CHECK_CONNECTION'));}
 public function verify(MailboxSync $sync){$r=$sync->verify();return back()->with('notice',$r['connected']&&$r['sender_matches']?'Gmail account identity matches the configured sender.':'Gmail connection needs authorization or the configured sender does not match.');}
 public function connect(MailboxSync $sync){$result=$sync->verify();if(empty($result['connected'])||empty($result['sender_matches']))return back()->withErrors(['gmail'=>'Complete Google OAuth authorization and read scopes for the configured sending account first.']);$mail=app(\App\Services\ProspectingProfile::class)->sender();$mail['provider']='gmail';$mail['sender_verified']=true;IntegrationSetting::updateOrCreate(['key'=>'mail'],['data'=>$mail]);\App\Models\OutboundMessage::where('status','approved')->update(['status'=>'draft','approval_hash'=>null,'approved_at'=>null,'error_code'=>'SENDER_CHANGED_REAPPROVE']);return back()->with('notice','Gmail OAuth sender identity and reply-read permission verified. Sending now uses Gmail; every message still requires fresh approval.');}
 public function classify(Request $r,InboxEvent $event){$d=$r->validate(['intent'=>'required|in:interested,not_interested,question,out_of_office,unsubscribe,bounce,reply']);$event->update(['intent'=>$d['intent'],'classification_method'=>'manual_operator_review']);$lead=\App\Models\Lead::find($event->lead_id);if($lead){$lead->reply_intent=$d['intent'];$lead->save();if(in_array($d['intent'],['unsubscribe','bounce'],true))app(\App\Services\OutboxService::class)->outcome($lead,$d['intent'],'manual_inbox_classification');}return back()->with('notice','Reply classification updated. This never resumes stopped outreach automatically.');}
 public function tick(MailboxSync $sync){set_time_limit(150);return response()->json($sync->sync());}
}

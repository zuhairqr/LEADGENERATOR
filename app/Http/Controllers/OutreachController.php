<?php
namespace App\Http\Controllers;
use App\Models\{Lead,OutboundMessage,OutreachEvent};
use App\Services\{AiDraftService,OutboxService,ProspectingProfile,SalesPipeline};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache,DB};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OutreachController {
    public function index(Request $request, ProspectingProfile $profile) {
        $filters = [
            'draft' => 'Needs review',
            'approved' => 'Queued',
            'sent' => 'Sent',
            'delivery_unknown' => 'Delivery unconfirmed',
        ];
        $status = $request->query('status');
        $status = is_string($status) && isset($filters[$status]) ? $status : null;
        $leadId = filter_var($request->query('lead'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $lead = $leadId ? Lead::findOrFail($leadId) : null;
        $messages = OutboundMessage::with('lead')->latest();
        if ($lead) {
            $messages->where('lead_id', $lead->id);
        }
        if ($status !== null) {
            $messages->where('status', $status);
        }

        return view('outreach', [
            'messages' => $messages->paginate(20)->appends(['status' => $status, 'lead' => $lead?->id]),
            'sender' => $profile->sender(),
            'filters' => $filters,
            'status' => $status,
            'leadFilter' => $lead,
        ]);
    }
    public function contact(Request $r,Lead $lead) {
        abort_if($lead->kind===\App\Services\MailConnectionTest::KIND,409,'Technical test recipients are fixed. Prepare a new owned-inbox test instead.');
        abort_if($lead->appliesOnSource(), 422, 'Use the original job listing to apply.');
        $d=$r->validate(['contact_email'=>['nullable','email','max:254']]);
        DB::transaction(function () use ($r,$lead,$d) {
            $fresh=Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            abort_if(OutboundMessage::where('lead_id',$fresh->id)->where('status','sending')->exists(),409,'A message is being sent. Wait for its result before changing the contact.');
            $fresh->contact_email=isset($d['contact_email'])?strtolower($d['contact_email']):null;
            $fresh->contact_permission_at=$r->boolean('contact_permission') && $fresh->contact_email?now():null; $fresh->save();
            OutboundMessage::where('lead_id',$fresh->id)->whereIn('status',['draft','approved'])->update(['recipient'=>$fresh->contact_email,'status'=>'draft','approval_hash'=>null,'approved_at'=>null]);
        });
        return back()->withFragment('lead-contact')->with('notice','Contact saved. Each outgoing email still needs approval.');
    }
    public function generate(Request $r,Lead $lead,AiDraftService $drafts) {
        $r->validate(['ai_consent'=>['accepted']]);
        try {
            $message = $drafts->generate($lead);
            if (!$message) {
                return redirect()->route('leads.show', $lead)->with('notice', 'AI review completed. Read the assessment on this lead.');
            }

            return redirect()->route('outreach.index', ['lead' => $lead->id])->withFragment('message-'.$message->id)
                ->with('notice', 'Open the message below to review its subject, recipient and content.');
        }
        catch (\Throwable $e) { return back()->withErrors(['ai'=>'Draft unavailable. Check the verified business profile, AI connection and daily limit. No email was sent.']); }
    }
    public function edit(Request $r,OutboundMessage $message,ProspectingProfile $profile) {
        abort_if($message->lead->kind===\App\Services\MailConnectionTest::KIND,409,'Technical test messages use a fixed, non-promotional template.');
        $d=$r->validate(['subject'=>['required','string','max:200','not_regex:/[\r\n]/'],'body'=>['required','string','max:12000'],'revision'=>['required','integer']]);
        $lock=Cache::lock('send:'.$message->id,60); abort_unless($lock->get(),409,'Sending is in progress.');
        try {
            $m=$message->fresh(); abort_unless(in_array($m->status,['draft','approved','failed'],true),409,'This message cannot be edited after dispatch.');
            abort_unless($m->revision===(int)$d['revision'],409,'The draft changed; reload before saving.');
            $m->update(['subject'=>$d['subject'],'body'=>$d['body'],'revision'=>$m->revision+1,'status'=>'draft','approval_hash'=>null,'approved_at'=>null,'error_code'=>null]);
            OutreachEvent::create(['lead_id'=>$m->lead_id,'message_id'=>$m->id,'event'=>'draft_edited']);
        } finally { $lock->release(); }
        return back()->with('notice','Draft saved. Any previous approval was removed.');
    }
    public function approve(Request $r,OutboundMessage $message,OutboxService $outbox) {
        $d=$r->validate(['approval'=>['accepted'],'digest'=>['required','string','size:64']]);
        try { $outbox->approve($message,$d['digest']); return back()->with('notice','This exact email is approved and queued. The worker will send it once.'); }
        catch (\Throwable $e) { return back()->withErrors(['approval'=>'Approval failed: review the latest draft, verified contact and sender settings.']); }
    }
    public function outcome(Request $r,Lead $lead,OutboxService $outbox) {
        $d=$r->validate(['outcome'=>['required',Rule::in(['reply','bounce','unsubscribe'])]]); $outbox->outcome($lead,$d['outcome']);
        return back()->with('notice','Outcome recorded manually. Pending messages and follow-ups were stopped.');
    }
    public function cancel(OutboundMessage $message) {
        $lock=Cache::lock('send:'.$message->id,60); abort_unless($lock->get(),409,'Sending is in progress.');
        try { $m=$message->fresh(); abort_unless(in_array($m->status,['draft','approved'],true),409);$m->update(['status'=>'cancelled','approval_hash'=>null,'approved_at'=>null]); }
        finally {$lock->release();} return back()->with('notice','Message cancelled.');
    }
    public function followup(OutboundMessage $message,AiDraftService $drafts) {
        abort_unless($message->status==='sent',422);
        abort_unless($message->sequence < (app(ProspectingProfile::class)->data()['max_followups'] ?? 2),422);
        try {$drafts->generate($message->lead,$message);return back()->with('notice','Follow-up draft prepared. Review and approve it before sending.');}
        catch (\Throwable $e) {return back()->withErrors(['followup'=>'Could not prepare this follow-up. Check profile, budget and reply/opt-out status.']);}
    }
    public function tick(SalesPipeline $pipeline) { set_time_limit(230); return response()->json($pipeline->tick()); }
    public function gmailEnvelope(Request $r) {
        $d=$r->validate(['message_id'=>'required|uuid']);
        return DB::transaction(function()use($d){$candidate=OutboundMessage::findOrFail($d['message_id']);$lead=Lead::whereKey($candidate->lead_id)->lockForUpdate()->firstOrFail();$m=OutboundMessage::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            abort_unless($m->status==='sending'&&$m->transport==='gmail'&&$m->approved_at&&$m->approval_hash&&hash_equals($m->approval_hash,$m->digest())&&!app(OutboxService::class)->closed($lead),403,'An exact approved send reservation is required.');
            abort_if($lead->kind===\App\Services\MailConnectionTest::KIND&&!app(\App\Services\MailConnectionTest::class)->validMessage($m),403,'Fixed technical test message required.');
            $key='gmail-envelope:'.$m->id.':'.$m->approval_hash;abort_unless(Cache::add($key,true,900),409,'This approved send was already claimed.');
            return response()->json(['messageId'=>$m->id,'recipient'=>$m->recipient,'subject'=>$m->subject,'body'=>$m->body,'fromEmail'=>$m->from_email,'fromName'=>$m->from_name,'parentMessageId'=>$m->parent_id?OutboundMessage::find($m->parent_id)?->message_id:null]);
        },3);
    }
    public function resolve(Request $r,OutboundMessage $message) {
        $d=$r->validate(['checked_provider'=>['accepted'],'resolution'=>['required',Rule::in(['confirmed_sent','confirmed_not_sent'])]]);
        $lock=Cache::lock('send:'.$message->id,60); abort_unless($lock->get(),409);
        try {
            $m=$message->fresh(); abort_unless($m->status==='delivery_unknown',409);
            if ($d['resolution']==='confirmed_sent') {
                $m->update(['status'=>'sent','sent_at'=>now(),'error_code'=>'MANUALLY_CONFIRMED_SENT','followup_due_at'=>now()->addDays((int)(app(ProspectingProfile::class)->data()['followup_days'] ?? 3))]);
                if (!app(OutboxService::class)->closed($m->lead)) { $lead=$m->lead; $lead->status='contacted'; $lead->save(); }
            } else $m->update(['status'=>'draft','revision'=>$m->revision+1,'approval_hash'=>null,'approved_at'=>null,'sending_at'=>null,'error_code'=>'MANUALLY_CONFIRMED_NOT_SENT']);
            OutreachEvent::create(['lead_id'=>$m->lead_id,'message_id'=>$m->id,'event'=>$d['resolution'],'details'=>['recorded_via'=>'manual_provider_check']]);
        } finally { $lock->release(); }
        return back()->with('notice','Provider check recorded. Any resend requires a new approval.');
    }
    public function receipt(Request $r,OutboxService $outbox) {
        $d=$r->validate(['sender'=>['required','email','max:254'],'references'=>['required','array','min:1','max:30'],'references.*'=>['string','max:255'],
            'outcome'=>['required',Rule::in(['reply','bounce','unsubscribe'])]]);
        $refs=array_map(fn ($x)=>trim($x,'<> '),$d['references']);
        $m=OutboundMessage::whereIn('status',['sent','delivery_unknown'])->where(function ($q) use ($refs) {$q->whereIn('message_id',$refs)->orWhereIn('thread_id',$refs);})
            ->where('recipient',strtolower($d['sender']))->latest()->first();
        if (!$m) return response()->json(['matched'=>false]);
        return response()->json(['matched'=>app(\App\Services\InboxProcessor::class)->record($d,'authenticated_callback')]);
    }
}

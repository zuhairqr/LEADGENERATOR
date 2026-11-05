<?php
namespace App\Services;
use App\Models\{Lead,OutboundMessage,OutreachEvent,IntegrationSecret};
use Illuminate\Support\Facades\{Cache,DB};

class OutboxService {
    public function __construct(private ProspectingProfile $profile,private DeliveryTransport $transport) {}
    public function closed(Lead $lead): bool { $notAllowed=$lead->kind===MailConnectionTest::KIND?!app(MailConnectionTest::class)->allowed($lead):!app(CountryPolicy::class)->allowed($lead);return (bool)($notAllowed || $lead->appliesOnSource() || $lead->listing_expires_at?->isPast() || $lead->replied_at || $lead->bounced_at || $lead->unsubscribed_at || in_array($lead->status,['won','lost','rejected'],true)
        || ($lead->contact_email && Lead::where('contact_email',$lead->contact_email)->where(fn ($q)=>$q->whereNotNull('unsubscribed_at')->orWhereNotNull('bounced_at'))->exists())); }
    public function approve(OutboundMessage $message,string $digest): void {
        DB::transaction(function () use ($message,$digest) {
            $lead=Lead::whereKey($message->lead_id)->lockForUpdate()->firstOrFail(); $m=OutboundMessage::whereKey($message->id)->lockForUpdate()->firstOrFail();
            $sender=$this->profile->sender();
            if ($m->status!=='draft' || !hash_equals($m->digest($sender),$digest)) throw new \RuntimeException('DRAFT_CHANGED_REVIEW_AGAIN');
            if($lead->kind===MailConnectionTest::KIND&&!app(MailConnectionTest::class)->validMessage($m))throw new \RuntimeException('FIXED_TEST_MESSAGE_REQUIRED');
            if ($this->closed($lead) || !$lead->contact_permission_at || !$m->recipient || $m->recipient!==$lead->contact_email || !filter_var($m->recipient,FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('VERIFIED_CONTACT_REQUIRED');
            if (!filter_var($sender['from_email'] ?? '',FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('SENDER_NOT_CONFIGURED');
            if (empty($sender['sender_verified'])) throw new \RuntimeException('SENDER_VERIFICATION_REQUIRED');
            if (($sender['provider'] ?? 'smtp')==='gmail' && !($this->profile->bridges()['gmail_ready'] ?? false)) throw new \RuntimeException('GMAIL_NOT_CONNECTED');
            if (($sender['provider'] ?? 'smtp')==='smtp' && !IntegrationSecret::find('smtp')) throw new \RuntimeException('SMTP_NOT_CONFIGURED');
            $m->from_email=$sender['from_email']; $m->from_name=$sender['from_name'] ?? ''; $m->transport=$sender['provider'] ?? 'smtp';
            $m->approval_hash=$m->digest(); $m->approved_at=now(); $m->status='approved'; $m->save();
            OutreachEvent::create(['lead_id'=>$lead->id,'message_id'=>$m->id,'event'=>'approved_for_send','details'=>['revision'=>$m->revision]]);
        });
    }
    public function dispatch(OutboundMessage $message): string {
        $lock=Cache::lock('send:'.$message->id,120); if (!$lock->get()) return 'busy';
        try {
            // Reserve under the same lead lock used by contact edits and incoming outcomes.
            // Contact edits then see "sending" and cannot replace the approved recipient mid-dispatch.
            [$m,$lead,$sender,$reserved]=DB::transaction(function () use ($message) {
                $lead=Lead::whereKey($message->lead_id)->lockForUpdate()->firstOrFail();
                $m=OutboundMessage::whereKey($message->id)->lockForUpdate()->firstOrFail();$sender=$this->profile->sender();
                if ($m->status!=='approved') return [$m,$lead,$sender,false];
                if ($this->closed($lead) || !$lead->contact_permission_at || $lead->contact_email!==$m->recipient) $m->update(['status'=>'cancelled','approval_hash'=>null]);
                elseif (!$m->approval_hash || !hash_equals($m->approval_hash,$m->digest()) || empty($sender['sender_verified']) || ($sender['from_name'] ?? '')!==$m->from_name || ($sender['from_email'] ?? '')!==$m->from_email || ($sender['provider'] ?? 'smtp')!==$m->transport || ($lead->kind===MailConnectionTest::KIND&&!app(MailConnectionTest::class)->validMessage($m)))
                    $m->update(['status'=>'draft','approval_hash'=>null,'approved_at'=>null,'error_code'=>'APPROVAL_INVALIDATED']);
                else {
                    // Persist before touching the provider; ambiguous sends are never auto-retried.
                    $m->update(['status'=>'sending','sending_at'=>now(),'error_code'=>null,
                        'message_id'=>$m->transport==='smtp' ? $m->id.'@'.substr(strrchr($m->from_email,'@'),1) : $m->message_id]);
                    return [$m,$lead,$sender,true];
                }
                return [$m,$lead,$sender,false];
            },3);
            if (!$reserved) return $m->status;
            try { $result=$this->transport->send($m,$sender); }
            catch (\Throwable $e) {
                $status=$e->getMessage()==='SMTP_NOT_CONFIGURED' ? 'failed' : 'delivery_unknown';
                $m->update(['status'=>$status,'error_code'=>$status==='failed'?'MAIL_NOT_CONFIGURED':'DELIVERY_UNCONFIRMED']);
                OutreachEvent::create(['lead_id'=>$lead->id,'message_id'=>$m->id,'event'=>$status]); return $status;
            }
            DB::transaction(function () use ($lead,$m,$result) {
                $fresh=Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();$closed=$this->closed($fresh);
                $m->update(['status'=>'sent','sent_at'=>now(),'message_id'=>$result['message_id'] ?? null,'thread_id'=>$result['thread_id'] ?? null,
                    'followup_due_at'=>$closed ? null : ($fresh->kind===MailConnectionTest::KIND?($m->sequence===0?now()->addMinutes(2):null):now()->addDays((int)($this->profile->data()['followup_days'] ?? 3)))]);
                if (!$closed) {$fresh->status='contacted'; $fresh->save();}
                OutreachEvent::create(['lead_id'=>$fresh->id,'message_id'=>$m->id,'event'=>'provider_accepted']);
            },3);
            return 'sent';
        } finally { $lock->release(); }
    }
    public function outcome(Lead $lead,string $outcome,string $via='manual'): void {
        DB::transaction(function () use ($lead,$outcome,$via) {
            $lead=Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $field=['reply'=>'replied_at','bounce'=>'bounced_at','unsubscribe'=>'unsubscribed_at'][$outcome] ?? throw new \InvalidArgumentException('Unknown outcome');
            if ($lead->$field) return;
            $lead->$field=now(); $lead->save();
            $ids=[$lead->id];
            if ($lead->contact_email && in_array($outcome,['bounce','unsubscribe'],true)) $ids=Lead::where('contact_email',$lead->contact_email)->pluck('id')->all();
            OutboundMessage::whereIn('lead_id',$ids)->whereIn('status',['draft','approved'])->update(['status'=>'cancelled','approval_hash'=>null,'approved_at'=>null]);
            OutboundMessage::whereIn('lead_id',$ids)->where('status','sent')->update(['followup_due_at'=>null]);
            \App\Models\ChannelMessage::whereIn('lead_id',$ids)->whereIn('status',['draft','approved'])->update(['status'=>'cancelled','approval_hash'=>null]);
            \App\Models\ChannelMessage::whereIn('lead_id',$ids)->where('status','sent')->update(['followup_due_at'=>null]);
            OutreachEvent::create(['lead_id'=>$lead->id,'event'=>$outcome,'details'=>['recorded_via'=>$via]]);
        });
    }
}

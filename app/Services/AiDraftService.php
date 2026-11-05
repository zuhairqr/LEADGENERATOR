<?php
namespace App\Services;
use App\Models\{Lead,AiAttempt,OutboundMessage,OutreachEvent};
use Illuminate\Support\Facades\{Cache,DB};
use Illuminate\Support\Str;

class AiDraftService {
    private function context(Lead $lead):string {return hash('sha256',json_encode([$lead->proposal,$lead->status,$lead->contact_email,$lead->title,$lead->description,$lead->business?->only(['name','website','country_code','geo_status','niche','location']),$lead->audits()->where('status','completed')->max('id')]));}
    public function __construct(private ProspectingProfile $profile, private ProposalWriter $writer) {}
    public function generate(Lead $lead, ?OutboundMessage $parent = null): ?OutboundMessage {
        if($lead->kind===MailConnectionTest::KIND)throw new \RuntimeException('TECHNICAL_TEST_HAS_NO_AI_PROPOSAL');
        if (!$this->profile->aiReady()) throw new \RuntimeException('BUSINESS_PROFILE_REQUIRED');
        $lock=Cache::lock('sales-draft:'.$lead->id,120);
        if (!$lock->get()) throw new \RuntimeException('DRAFT_IN_PROGRESS');
        $attempt=null;
        try {
            $lead->refresh(); $sequence=$parent ? $parent->sequence+1 : 0;
            if ($parent && ($parent->lead_id!==$lead->id || $parent->fresh()->status!=='sent' || $sequence>(int)($this->profile->data()['max_followups'] ?? 2))) throw new \RuntimeException('FOLLOWUP_NOT_ALLOWED');
            if (app(OutboxService::class)->closed($lead)) throw new \RuntimeException('LEAD_CLOSED');
            if ($existing=OutboundMessage::where('lead_id',$lead->id)->where('sequence',$sequence)->first()) return $existing;
            $budget=Cache::lock('sales-ai-budget',10); if (!$budget->get()) throw new \RuntimeException('AI_BUDGET_BUSY');
            try {
                $limit=(int)($this->profile->data()['daily_ai_limit'] ?? 5);
                if (AiAttempt::where('created_at','>=',now()->subHours(24))->count() >= $limit) throw new \RuntimeException('AI_DAILY_LIMIT');
                $attempt=AiAttempt::create(['id'=>(string)Str::uuid(),'lead_id'=>$lead->id,'kind'=>$parent?'followup':'initial','status'=>'running']);
            } finally { $budget->release(); }
            $profileHash=hash('sha256',json_encode($this->profile->data()));
            $lead->ai_state='generating'; $lead->save(); $version=$lead->updated_at->format('Y-m-d H:i:s');
            $leadHash=$this->context($lead);
            $result=$this->writer->draft($lead,$parent);
            return DB::transaction(function () use ($lead,$parent,$sequence,$result,$version,$profileHash,$leadHash,$attempt) {
                $fresh=Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
                if ($fresh->updated_at->format('Y-m-d H:i:s') !== $version || in_array($fresh->status,['won','lost','rejected'],true)
                    || $fresh->replied_at || $fresh->unsubscribed_at || $fresh->bounced_at
                    || $leadHash !== $this->context($fresh) || !app(CountryPolicy::class)->allowed($fresh)
                    || $profileHash !== hash('sha256',json_encode($this->profile->data()))) throw new \RuntimeException('DRAFT_CONTEXT_CHANGED');
                $fresh->ai_score=$result['score']; $fresh->ai_reason=$result['reason']; $fresh->ai_qualified=$result['qualified'];
                $fresh->ai_state='completed'; $fresh->ai_model=$this->profile->data()['ai_model'] ?? 'gpt-6.1-sol';
                if (!$parent) $fresh->proposal=$result['proposal']; $fresh->drafted_at=now(); $fresh->save();
                $attempt->update(['status'=>'completed']);
                if (!$result['qualified']) { if ($parent) $parent->update(['followup_due_at'=>null]); OutreachEvent::create(['lead_id'=>$fresh->id,'event'=>$parent?'followup_declined':'ai_not_qualified']); return null; }
                $m=OutboundMessage::create(['id'=>(string)Str::uuid(),'lead_id'=>$fresh->id,'sequence'=>$sequence,
                    'parent_id'=>$parent?->id,'kind'=>$parent?'followup':'initial','recipient'=>$fresh->contact_email,
                    'subject'=>$result['email_subject'],'body'=>$result['email_body'],'status'=>'draft']);
                OutreachEvent::create(['lead_id'=>$fresh->id,'message_id'=>$m->id,'event'=>'draft_created']);
                return $m->fresh();
            });
        } catch (\Throwable $e) {
            if ($attempt) { $attempt->update(['status'=>'failed','error_code'=>'DRAFT_FAILED']); $lead->refresh(); if ($lead->ai_state==='generating') {$lead->ai_state='failed';$lead->save();} }
            throw new \RuntimeException($attempt ? 'DRAFT_FAILED' : $e->getMessage());
        } finally { $lock->release(); }
    }
}

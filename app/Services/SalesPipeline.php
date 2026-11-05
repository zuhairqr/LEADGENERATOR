<?php
namespace App\Services;
use App\Models\{Lead,OutboundMessage,IntegrationSetting};
use Illuminate\Support\Facades\Cache;
class SalesPipeline {
    public function __construct(private ProspectingProfile $profile,private AiDraftService $drafts,private OutboxService $outbox) {}
    public function tick(): array {
        $lock=Cache::lock('sales-pipeline-tick',240); if (!$lock->get()) return ['status'=>'busy'];
        $summary=['sent'=>0,'drafts'=>0,'followups'=>0,'errors'=>0];
        try {
            $bridges=$this->profile->bridges();
            if(($bridges['inbox_ready']??false)&&($this->profile->sender()['provider']??'')==='gmail'){
                $inbox=app(MailboxSync::class)->sync();if(empty($inbox['ok']))return ['status'=>'held_for_mailbox_check','sent'=>0,'drafts'=>0,'followups'=>0,'errors'=>1];
            }
            $d = $this->profile->data();
            if ($d['automatic_outreach_paused'] ?? false) {
                $result = $summary + ['status' => 'outreach_paused', 'ai_ready' => $this->profile->aiReady()];
                IntegrationSetting::updateOrCreate(['key'=>'sales_runtime'], ['data'=>$result+['checked_at'=>now()->toIso8601String()]]);
                return $result;
            }
            OutboundMessage::where('status','sending')->where('sending_at','<',now()->subMinutes(10))->update(['status'=>'delivery_unknown','error_code'=>'WORKER_INTERRUPTED']);
            foreach (OutboundMessage::where('status','approved')->orderBy('approved_at')->limit(2)->get() as $m) if ($this->outbox->dispatch($m)==='sent') $summary['sent']++;
            \App\Models\ChannelMessage::where('status','sending')->where('updated_at','<',now()->subMinutes(10))->update(['status'=>'delivery_unknown','error_code'=>'WORKER_INTERRUPTED']);
            foreach(\App\Models\ChannelMessage::where('status','approved')->oldest('approved_at')->limit(2)->get() as $m)if(app(ChannelOutbox::class)->dispatch($m)==='sent')$summary['sent']++;
            $summary['followups']+=app(MailConnectionTest::class)->draftDue();
            if (($d['auto_draft'] ?? false) && $this->profile->aiReady()) {
                $platformSources = array_keys(array_filter(config('leadgen.sources', []), fn ($source) => !empty($source['apply_on_source'])));
                $parent=OutboundMessage::where('status','sent')->where('followup_due_at','<=',now())->where('sequence','<',$d['max_followups'] ?? 2)
                    ->whereHas('lead',fn($q)=>app(CountryPolicy::class)->scope($q->prospects()->openListings()->whereNotIn('source',$platformSources)))
                    ->whereDoesntHave('lead',fn ($q)=>$q->where('ai_state','failed')->orWhereNotNull('replied_at')->orWhereNotNull('bounced_at')->orWhereNotNull('unsubscribed_at')->orWhereIn('status',['won','lost','rejected']))
                    ->whereNotIn('id',OutboundMessage::whereNotNull('parent_id')->select('parent_id'))->oldest('followup_due_at')->first();
                if ($parent) { try { if ($this->drafts->generate($parent->lead,$parent)) $summary['followups']++; } catch (\Throwable $e) { $summary['errors']++; } }
                else {
                    $channelParent=\App\Models\ChannelMessage::where('status','sent')->whereNotNull('followup_due_at')->where('followup_due_at','<=',now())->where('sequence','<',$d['max_followups']??2)->whereNull('error_code')->whereNotIn('id',\App\Models\ChannelMessage::whereNotNull('parent_id')->select('parent_id'))->oldest('followup_due_at')->get()->first(fn($m)=>!$this->outbox->closed($m->lead));
                    if($channelParent){try{if(app(ChannelFollowup::class)->draft($channelParent))$summary['followups']++;}catch(\Throwable $e){$summary['errors']++;}$lead=null;}
                    $suppressed=Lead::whereNotNull('contact_email')->where(fn ($q)=>$q->whereNotNull('unsubscribed_at')->orWhereNotNull('bounced_at'))->select('contact_email');
                    $lead=isset($channelParent)&&$channelParent ? null : Lead::prospects()->openListings()->whereNotIn('source',$platformSources)->whereIn('status',['new','shortlisted'])->where('ai_state','pending')->where('match_score','>=',$d['minimum_score'] ?? 52)
                        ->where(function($q){$q->where(function($r){$r->where('kind','!=','business');if(\App\Models\IntegrationSetting::find('business_discovery')?->data['require_country_review']??false)$r->whereNotNull('country_reviewed_at')->whereNotNull('country_code')->whereNotIn('country_code',app(CountryPolicy::class)->excluded());})->orWhereHas('business',fn($r)=>$r->where('geo_status','allowed')->whereNotIn('country_code',app(CountryPolicy::class)->excluded()));})
                        ->where(fn ($q)=>$q->whereNull('contact_email')->orWhereNotIn('contact_email',$suppressed))->orderByDesc('match_score')->oldest()->first();
                    if ($lead) { try { if ($this->drafts->generate($lead)) $summary['drafts']++; } catch (\Throwable $e) { $summary['errors']++; } }
                }
            }
            $result=$summary+['ai_ready'=>$this->profile->aiReady()];
            IntegrationSetting::updateOrCreate(['key'=>'sales_runtime'],['data'=>$result+['checked_at'=>now()->toIso8601String()]]);
            return $result;
        } finally { $lock->release(); }
    }
}

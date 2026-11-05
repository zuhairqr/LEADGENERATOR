<?php
namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\CollectionRun;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController
{
    public function index(Request $request)
    {
        $filterSources=config('leadgen.sources',[]);foreach(Lead::prospects()->distinct()->pluck('source') as $id)$filterSources[$id]??=['label'=>ucfirst(str_replace('-',' ',$id))];
        $skillNames = array_keys(app(\App\Services\ProspectingProfile::class)->skills());
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(Lead::STATUSES)],
            'min_score' => ['nullable', 'integer', 'between:0,100'],
            'source' => ['nullable', Rule::in(array_keys($filterSources))],
            'kind'=>['nullable',Rule::in(['request','business'])],
            'contact'=>['nullable',Rule::in(['public','verified'])],
            'service' => ['nullable', Rule::in($skillNames)],
        ]);
        $query = Lead::prospects()->openListings();
        if(!empty($filters['kind']))$query->where('kind',$filters['kind']);
        if (!empty($filters['source'])) $query->where('source', $filters['source']);
        if (!empty($filters['service'])) $query->whereJsonContains('matched_services', $filters['service']);
        if (empty($filters['status'])) $query->where('status', '!=', 'rejected');
        if (!empty($filters['q'])) {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']);
            $query->where('title', 'like', '%'.$term.'%');
        }
        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        if (isset($filters['min_score'])) $query->where('match_score', '>=', $filters['min_score']);
        if (($filters['contact'] ?? null) === 'verified') {
            $query->whereNotNull('contact_email')->whereNotNull('contact_permission_at');
        } elseif (($filters['contact'] ?? null) === 'public') {
            $query->where(fn ($lead) => $lead->whereNotNull('contact_email')->orWhereHas('business',
                fn ($business) => $business->where(fn ($contact) => $contact
                    ->where(fn ($phone) => $phone->whereNotNull('phone')->where('phone', '!=', ''))
                    ->orWhereJsonLength('contacts', '>', 0))));
        }
        $stats = [
            'total' => Lead::prospects()->where('status', '!=', 'rejected')->count(),
            'rejected' => Lead::prospects()->where('status', 'rejected')->count(),
            'shortlisted' => Lead::prospects()->where('status', 'shortlisted')->count(),
            'drafts' => Lead::prospects()->whereNotNull('proposal')->count(),
            'won' => Lead::prospects()->where('status', 'won')->count(),
        ];
        $pipeline = ['drafts'=>\App\Models\OutboundMessage::sales()->where('status','draft')->count(),
            'approved'=>\App\Models\OutboundMessage::sales()->where('status','approved')->count(),
            'sent'=>\App\Models\OutboundMessage::sales()->where('status','sent')->count(),
            'unknown'=>\App\Models\OutboundMessage::sales()->where('status','delivery_unknown')->count()];
        return view('dashboard', [
            'sources' => config('leadgen.sources', []),
            'selectedSources' => app(\App\Services\ProspectingProfile::class)->data()['enabled_sources'] ?? null,
            'filterSources'=>$filterSources,
            'collectionRuns' => CollectionRun::latest()->limit(12)->get(),
            'skillNames'=>$skillNames,
            'salesRuntime'=>\App\Models\IntegrationSetting::find('sales_runtime')?->data ?? [],
            'pipeline'=>$pipeline,
            'overview'=>$this->overview($stats, $pipeline),
            'leads' => $query->with('business')->orderByDesc('match_score')->orderByDesc('id')->paginate(20)->withQueryString(),
            'stats' => $stats,
        ]);
    }

    private function overview(array $stats, array $pipeline): array
    {
        $end=now('UTC'); $start=$end->copy()->startOfDay()->subDays(6);
        $daily=Lead::prospects()->whereBetween('created_at',[$start,$end])
            ->selectRaw("DATE(created_at) AS day, COUNT(*) AS total, SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) AS won")
            ->groupByRaw('DATE(created_at)')->get()->keyBy('day');
        $days=[];
        for($i=0;$i<7;$i++) {
            $day=$start->copy()->addDays($i); $row=$daily->get($day->toDateString());
            $count=(int)($row->total??0); $won=(int)($row->won??0);
            $days[]=['label'=>$day->format('d M'),'total'=>$count,'won'=>$won,'rate'=>$count?round(100*$won/$count,1):null];
        }
        $all=$stats['total']+$stats['rejected'];
        $qualified=Lead::prospects()->whereIn('status',['shortlisted','approved','contacted','won'])->count();
        $sentLeads=\App\Models\OutboundMessage::sales()->where('status','sent')->distinct()->count('lead_id');
        $replied=Lead::prospects()->whereNotNull('replied_at')->whereIn('id',\App\Models\OutboundMessage::sales()->where('status','sent')->select('lead_id'))->count();
        $profile=app(\App\Services\ProspectingProfile::class);
        $sync=\App\Models\IntegrationSetting::find('mailbox_sync')?->data??[];
        $sources=config('leadgen.sources',[]);
        return ['days'=>$days,'new_leads'=>array_sum(array_column($days,'total')),'all_records'=>$all,
            'qualified'=>$qualified,'qualification_rate'=>$stats['total']?round(100*$qualified/$stats['total'],1):null,
            'win_rate'=>$all?round(100*$stats['won']/$all,1):null,
            'sent_leads'=>$sentLeads,'replied_leads'=>$replied,'reply_rate'=>$sentLeads?round(100*$replied/$sentLeads,1):null,
            'approval_rate'=>($pipeline['drafts']+$pipeline['approved'])?round(100*$pipeline['approved']/($pipeline['drafts']+$pipeline['approved']),1):null,
            'enabled_sources'=>count(array_filter($sources,fn($s)=>!empty($s['permitted']))),'source_count'=>count($sources),
            'source_totals'=>Lead::prospects()->selectRaw('source, COUNT(*) AS total')->groupBy('source')->orderByDesc('total')->get(),
            'newest'=>Lead::prospects()->openListings()->where('status','!=','rejected')->latest('id')->limit(5)->get(),
            'gmail_ready'=>!empty($profile->bridges()['gmail_ready']),'sync_ok'=>!empty($sync['ok']),
            'sync_checked_at'=>$sync['checked_at']??null,'date_label'=>$start->format('d M').' – '.$end->format('d M Y')];
    }

    public function show(Lead $lead)
    {
        if ($lead->kind === \App\Services\MailConnectionTest::KIND) {
            return redirect()->route('mail-test.index');
        }

        $lead->load('business');
        $website = $lead->business?->website;
        $websiteAudit = $website
            ? $lead->audits()->where('requested_url', $website)->where('status', 'completed')->latest('id')->first()
            : null;
        return view('lead', [
            'lead' => $lead,
            'events' => \App\Models\OutreachEvent::where('lead_id', $lead->id)->latest()->limit(50)->get(),
            'countryReady' => app(\App\Services\CountryPolicy::class)->allowed($lead),
            'websiteChecked' => $websiteAudit !== null,
            'contactPages' => $websiteAudit?->evidence['contact_page_urls'] ?? $websiteAudit?->evidence['contact_links'] ?? [],
        ]);
    }

    public function update(Request $request, Lead $lead)
    {
        abort_if($lead->kind===\App\Services\MailConnectionTest::KIND,409,'Technical tests are managed on their own page.');
        $data = $request->validate([
            'status' => ['required', Rule::in(Lead::STATUSES)],
            'proposal' => ['nullable', 'string', 'max:12000'],
        ]);
        $lead->status = $data['status'];
        $lead->proposal = $data['proposal'] ?? null;
        $lead->save();
        return back()->with('notice', 'Saved. Nothing was sent to the client.');
    }

}

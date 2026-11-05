@extends('layout')
@section('title', 'My leads')
@section('content')
@php
    $chartMax=max(4,(int)(ceil(max(array_column($overview['days'],'total'))/4)*4));
    $rates=array_values(array_filter(array_column($overview['days'],'rate'),fn($v)=>$v!==null));
    $rateMax=max(10,(int)(ceil(($rates?max($rates):0)/10)*10));
    $ratePath=''; $previousRate=false;
    foreach($overview['days'] as $i=>$day){
        if($day['rate']===null){$previousRate=false;continue;}
        $ratePath.=($previousRate?' L ':' M ').(38+$i*42).' '.(190-150*$day['rate']/$rateMax);
        $previousRate=true;
    }
@endphp
<section class="workspace-hero" aria-labelledby="workspace-title">
    <div class="hero-copy">
        <div class="eyebrow"><span class="workspace-dot" aria-hidden="true"></span> LEAD WORKSPACE</div>
        <h1 id="workspace-title">My leads</h1>
        <p>Find new opportunities, choose the right leads, and keep track of every conversation.</p>
    </div>
    <dl class="hero-metrics">
        <div><dt>Saved leads</dt><dd>{{ number_format($stats['total']) }}</dd></div>
        <div><dt>Lead drafts</dt><dd>{{ number_format($pipeline['drafts']) }}</dd></div>
        <div><dt>Projects won</dt><dd>{{ number_format($stats['won']) }}</dd></div>
    </dl>
</section>
<nav class="workflow-grid" aria-label="Lead workflow">
    <a class="workflow-card workflow-primary" href="{{ route('discovery.index') }}">
        <span class="workflow-card-top"><span class="workflow-icon-tile"><x-workspace-icon name="search" /></span><span class="workflow-number" aria-hidden="true">01</span></span>
        <span><strong>Find leads <span class="workflow-arrow" aria-hidden="true">↗</span></strong><small>Search the web or add your own list.</small></span>
    </a>
    <a class="workflow-card" href="#lead-inbox">
        <span class="workflow-card-top"><span class="workflow-icon-tile"><x-workspace-icon name="leads" /></span><span class="workflow-number" aria-hidden="true">02</span></span>
        <span><strong>Review leads <span class="workflow-arrow" aria-hidden="true">↓</span></strong><small>Check the fit and shortlist opportunities.</small></span>
    </a>
    <a class="workflow-card" href="{{ route('outreach.index', ['status' => 'draft']) }}">
        <span class="workflow-card-top"><span class="workflow-icon-tile"><x-workspace-icon name="email" /></span><span class="workflow-number" aria-hidden="true">03</span></span>
        <span><strong>Review email drafts <span class="workflow-arrow" aria-hidden="true">↗</span></strong><small>Read and approve each message.</small></span>
    </a>
    <a class="workflow-card" href="{{ route('inbox.index') }}">
        <span class="workflow-card-top"><span class="workflow-icon-tile"><x-workspace-icon name="replies" /></span><span class="workflow-number" aria-hidden="true">04</span></span>
        <span><strong>Check replies <span class="workflow-arrow" aria-hidden="true">↗</span></strong><small>Read replies linked to your leads.</small></span>
    </a>
</nav>
<section class="panel lead-list" id="lead-inbox" aria-labelledby="lead-inbox-heading" tabindex="-1">
    <div class="section-heading">
        <div>
            <h2 id="lead-inbox-heading">Lead inbox</h2>
            <p class="muted">Open a lead for its contact details and next steps. <a href="{{ route('dashboard', ['kind' => 'business', 'contact' => 'public']) }}#lead-inbox">See businesses with an email or phone</a>.</p>
        </div>
        <span class="result-count">{{ number_format($leads->total()) }} {{ \Illuminate\Support\Str::plural('result', $leads->total()) }}</span>
    </div>
    <form method="get" action="{{ route('dashboard') }}#lead-inbox" class="lead-filters">
        <div class="filters">
            <label class="search">Search leads<input name="q" maxlength="150" placeholder="Business name or project title" value="{{ request('q') }}"></label>
            <label>Status<select name="status"><option value="">All except rejected</option>@foreach(\App\Models\Lead::STATUSES as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
            <button>Apply filters</button>
            <a href="{{ route('dashboard') }}#lead-inbox">Clear filters</a>
        </div>
        <details class="filter-details" @if(request()->anyFilled(['source', 'kind', 'contact', 'min_score', 'service'])) open @endif>
            <summary>More filters</summary>
            <div class="filters">
                <label>Source<select name="source"><option value="">All sources</option>@foreach($filterSources as $sourceId => $source)<option value="{{ $sourceId }}" @selected(request('source') === $sourceId)>{{ $source['label'] ?? $sourceId }}</option>@endforeach</select></label>
                <label>Lead type<select name="kind"><option value="">All types</option><option value="business" @selected(request('kind') === 'business')>Businesses</option><option value="request" @selected(request('kind') === 'request')>Client requests and jobs</option></select></label>
                <label>Service<select name="service"><option value="">All services</option>@foreach($skillNames as $serviceName)<option value="{{ $serviceName }}" @selected(request('service') === $serviceName)>{{ $serviceName }}</option>@endforeach</select></label>
                <label>Contact details<select name="contact"><option value="">Any</option><option value="public" @selected(request('contact') === 'public')>Email or phone</option><option value="verified" @selected(request('contact') === 'verified')>Verified email</option></select></label>
                <label>Minimum match score<input name="min_score" type="number" min="0" max="100" placeholder="0–100" value="{{ request('min_score') }}"></label>
                <button>Apply filters</button>
            </div>
        </details>
    </form>

    @if($leads->isNotEmpty())
        <p class="muted small">The match score reflects service keywords for requests and website findings for businesses.</p>
        <div class="table-wrap">
            <table class="lead-table" role="table">
                <thead role="rowgroup"><tr role="row"><th scope="col">Lead</th><th scope="col">Services</th><th scope="col">Match</th><th scope="col">Budget</th><th scope="col">Status</th><th scope="col">Contact</th></tr></thead>
                <tbody role="rowgroup">
                @foreach($leads as $lead)
                    <tr role="row">
                        <td role="cell"><a class="project-title" href="{{ route('leads.show', $lead) }}">{{ $lead->title }}</a><div class="muted small">{{ $filterSources[$lead->source]['label'] ?? $lead->source }} · {{ $lead->created_at->format('d M Y') }}</div></td>
                        <td role="cell"><span class="mobile-field" aria-hidden="true">Services</span><div class="tags">@forelse($lead->matched_services as $service)<span class="tag">{{ $service }}</span>@empty<span class="muted">Needs review</span>@endforelse</div></td>
                        <td role="cell"><span class="mobile-field" aria-hidden="true">Match</span><span class="score">{{ $lead->match_score }}<small>/100</small></span></td>
                        <td role="cell"><span class="mobile-field" aria-hidden="true">Budget</span>@if($lead->budget_min !== null || $lead->budget_max !== null){{ $lead->currency }} {{ $lead->budget_min ?? '?' }} – {{ $lead->budget_max ?? '?' }}@else<span class="muted">Not listed</span>@endif</td>
                        <td role="cell"><span class="mobile-field" aria-hidden="true">Status</span><span class="status-badge" data-status="{{ $lead->status }}">{{ ucfirst($lead->status) }}</span></td>
                        <td role="cell">
                            <span class="mobile-field" aria-hidden="true">Contact</span>
                            <a class="contact-link" href="{{ route('leads.show', $lead) }}#lead-contact">
                                @if($lead->appliesOnSource())
                                    Apply on source
                                @elseif($lead->contact_email)
                                    {{ $lead->contact_permission_at ? 'Verified email' : 'Review email' }}
                                @elseif($lead->business?->contacts || $lead->business?->phone)
                                    Public contact
                                @elseif($lead->kind === 'business')
                                    Find contact
                                @else
                                    Contact options
                                @endif
                                <span aria-hidden="true">→</span>
                            </a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="pagination">
            <span class="muted small">{{ $leads->total() }} matching {{ \Illuminate\Support\Str::plural('lead', $leads->total()) }} · Page {{ $leads->currentPage() }} of {{ $leads->lastPage() }}</span>
            @php($leads->fragment('lead-inbox'))
            <div>@if($leads->previousPageUrl())<a href="{{ $leads->previousPageUrl() }}">← Previous</a>@endif @if($leads->nextPageUrl())<a href="{{ $leads->nextPageUrl() }}">Next →</a>@endif</div>
        </div>
    @else
        <div class="empty">
            @if(request()->anyFilled(['q', 'status', 'source', 'kind', 'contact', 'min_score', 'service', 'page']))
                <h3>No matching leads</h3>
                <p class="muted">Try a different search or clear the filters to see your leads.</p>
                <a href="{{ route('dashboard') }}#lead-inbox">Clear filters</a>
            @else
                <h3>No leads to review yet</h3>
                <p class="muted">Search for businesses or add a lead you already know.</p>
                <a class="button-link" href="{{ route('discovery.index') }}">Find your first lead</a>
            @endif
        </div>
    @endif
</section>
<details class="panel workspace-details">
    <summary>Lead and email performance</summary>
    <p class="muted">See how many leads you found, how many turned into projects and how people responded to your emails.</p>
<div class="stats overview-stats">
@foreach([['total','Active leads','↗'],['shortlisted','Shortlisted','◈'],['drafts','Proposal drafts','✦'],['won','Projects won','✓']] as [$key,$label,$icon])
<section class="stat"><div class="stat-label"><span>{{ $label }}</span><span class="stat-icon" aria-hidden="true">{{ $icon }}</span></div><strong>{{ number_format($stats[$key]) }}</strong><span class="stat-caption">{{ $key==='won'?'Recorded project outcomes':($key==='drafts'?'Prepared for human review':'Across your saved pipeline') }}</span></section>
@endforeach
</div>
<div class="analytics-grid">
    <section class="metric-card lead-volume">
        <div class="card-heading"><h2>Leads generated</h2><span class="card-kicker">PAST 7 DAYS</span></div>
        <div class="volume-content"><div class="volume-summary"><strong class="hero-number">{{ number_format($overview['new_leads']) }}</strong><span class="muted">Captured leads</span><span class="metric-footnote">All captured records · includes rejected leads</span></div>
        <figure class="bar-chart"><svg viewBox="0 0 440 210" role="img" aria-label="Leads captured per day over the last seven days">
            @foreach([0,1,2] as $tick)<line class="chart-grid" x1="32" y1="{{ 20+75*$tick }}" x2="433" y2="{{ 20+75*$tick }}"/><text class="chart-axis" x="25" y="{{ 24+75*$tick }}" text-anchor="end">{{ $chartMax*(2-$tick)/2 }}</text>@endforeach
            @foreach($overview['days'] as $day)<g><title>{{ $day['label'] }}: {{ $day['total'] }} leads</title><rect class="chart-bar" x="{{ 48+$loop->index*56 }}" y="{{ 170-150*$day['total']/$chartMax }}" width="23" height="{{ 150*$day['total']/$chartMax }}" rx="3"/><text class="chart-axis" text-anchor="middle" x="{{ 59.5+$loop->index*56 }}" y="197">{{ $day['label'] }}</text></g>@endforeach
        </svg><figcaption>New records by capture date · UTC</figcaption></figure></div>
        <details class="chart-data"><summary>View daily numbers</summary><table><thead><tr><th>Date (UTC)</th><th>Captured</th><th>Won</th></tr></thead><tbody>@foreach($overview['days'] as $day)<tr><td>{{ $day['label'] }}</td><td>{{ $day['total'] }}</td><td>{{ $day['won'] }}</td></tr>@endforeach</tbody></table></details>
    </section>
    <section class="metric-card performance-card">
        <div class="card-heading"><h2>Pipeline health</h2><span class="card-mark" aria-hidden="true">◈</span></div>
        <div class="qualification-tile"><span class="metric-overline">QUALIFICATION RATE</span><strong>{{ $overview['qualification_rate']===null?'—':number_format($overview['qualification_rate'],1).'%' }}</strong><p>{{ $overview['qualified'] }} of {{ $stats['total'] }} active leads shortlisted or further</p></div>
        @include('components.gauge',['value'=>$overview['source_count']?100*$overview['enabled_sources']/$overview['source_count']:null,'label'=>'Sources enabled','hint'=>$overview['enabled_sources'].' of '.$overview['source_count'].' configured sources','tone'=>'violet'])
        <a href="{{ route('inbox.index') }}" @class(['connection-chip','is-connected'=>$overview['gmail_ready']])><span class="connection-check" aria-hidden="true">{{ $overview['gmail_ready']?'✓':'○' }}</span><span><strong>{{ $overview['gmail_ready']?'Gmail connected':'Connect your mailbox' }}</strong><small>{{ $overview['gmail_ready']?'Sender & read access verified':'Finish account authorization' }}</small></span><span aria-hidden="true">↗</span></a>
    </section>
    <section class="metric-card conversion-card"><div class="card-heading"><h2>Conversion rate</h2><span class="card-mark" aria-hidden="true">↗</span></div><div class="conversion-total"><strong>{{ $overview['win_rate']===null?'—':number_format($overview['win_rate'],1).'%' }}</strong><span class="muted">{{ $stats['won'] }} won / {{ $overview['all_records'] }} captured</span></div>
        <figure class="line-chart"><svg viewBox="0 0 310 225" role="img" aria-label="Won leads as a percentage of leads captured on each day; days without records have no data">
            @foreach([0,1,2] as $tick)<line class="chart-grid" x1="35" x2="301" y1="{{ 40+75*$tick }}" y2="{{ 40+75*$tick }}"/><text class="chart-axis" text-anchor="end" x="29" y="{{ 44+75*$tick }}">{{ $rateMax*(2-$tick)/2 }}%</text>@endforeach
            @if($ratePath)<path class="chart-line" d="{{ $ratePath }}"/>@endif
            @foreach($overview['days'] as $day)@if($day['rate']!==null)<circle class="chart-point" cx="{{ 38+$loop->index*42 }}" cy="{{ 190-150*$day['rate']/$rateMax }}" r="4"><title>{{ $day['label'] }}: {{ $day['won'] }} won of {{ $day['total'] }} captured ({{ $day['rate'] }}%)</title></circle>@endif @if($loop->index%2===0)<text class="chart-axis" text-anchor="middle" x="{{ 38+$loop->index*42 }}" y="216">{{ $day['label'] }}</text>@endif @endforeach
        </svg><figcaption>Won / captured, grouped by capture date. Days with no records are gaps.</figcaption></figure>
        <div class="conversion-note"><span class="status-dot" aria-hidden="true"></span><p>{{ count($rates)?'Based on recorded lead outcomes.':'Your trend will appear as leads arrive.' }} </p></div><a class="card-bottom-link" href="{{ route('reports') }}">Open reports <span aria-hidden="true">↗</span></a>
    </section>
    <section class="metric-card email-card"><div class="card-heading"><h2>Email activity</h2><a href="{{ route('outreach.index') }}">Review queue <span aria-hidden="true">↗</span></a></div><div class="email-content"><div class="volume-summary"><strong class="hero-number">{{ number_format($pipeline['sent']) }}</strong><span class="muted">Provider accepted</span><span class="metric-footnote">{{ $pipeline['drafts'] }} drafts · {{ $pipeline['approved'] }} approved<br>{{ $pipeline['unknown'] }} delivery checks needed</span></div>@include('components.gauge',['value'=>$overview['approval_rate'],'label'=>'Queue approved','hint'=>'Approved / drafts + approved','tone'=>'coral'])@include('components.gauge',['value'=>$overview['reply_rate'],'label'=>'Reply rate','hint'=>$overview['sent_leads']?$overview['replied_leads'].' replied / '.$overview['sent_leads'].' contacted leads':'No sent conversations yet','tone'=>'cyan'])</div><div class="card-disclaimer">Every message needs your approval. Provider acceptance does not confirm inbox delivery.</div></section>
    <section class="metric-card source-card"><div class="card-heading"><h2>Leads by source</h2><span class="card-kicker">ALL CAPTURED RECORDS</span></div><div class="ranked-list">@forelse($overview['source_totals'] as $row)<div class="source-row"><div><span>{{ $filterSources[$row->source]['label']??ucfirst(str_replace('-',' ',$row->source)) }}</span><strong>{{ number_format($row->total) }}</strong></div><div class="source-track" aria-hidden="true"><span style="width:{{ $overview['all_records']?100*$row->total/$overview['all_records']:0 }}%"></span></div></div>@empty<div class="widget-empty"><span aria-hidden="true">◎</span><p>Your source breakdown will appear here.</p><a href="{{ route('discovery.index') }}">Find your first leads ↗</a></div>@endforelse</div></section>
    <section class="metric-card newest-card"><div class="card-heading"><h2>Newest leads</h2><a href="#lead-inbox">View all <span aria-hidden="true">↗</span></a></div><div class="ranked-list">@forelse($overview['newest'] as $recent)<a class="newest-row" href="{{ route('leads.show',$recent) }}"><span class="lead-avatar" aria-hidden="true">{{ mb_substr($recent->title,0,1) }}</span><span class="newest-name"><strong>{{ $recent->title }}</strong><small>{{ $recent->source }} · {{ $recent->created_at->format('d M') }}</small></span><span class="tag">{{ ucfirst($recent->status) }}</span><span class="newest-arrow" aria-hidden="true">↗</span></a>@empty<div class="widget-empty"><span aria-hidden="true">↗</span><p>Your newest opportunities will appear here.</p></div>@endforelse</div></section>
</div>
</details>
@if(in_array(request()->attributes->get('operator_role'), ['owner', 'admin'], true))
<details class="panel workspace-details"><summary>Collection sources and recent runs</summary>
<p class="muted">See where leads come from and when each source was last checked.</p><p><a href="{{ route('connections.edit') }}">Manage connections</a></p>
<div class="table-wrap"><table><thead><tr><th>Source</th><th>Connection</th><th>Coverage</th></tr></thead><tbody>
@foreach($sources as $sourceId => $source)<tr><td>{{ $source['label'] ?? $sourceId }}</td><td>{{ ($source['permitted'] ?? false) ? (is_array($selectedSources) && !in_array($sourceId, $selectedSources, true) ? 'Paused' : 'Enabled') : 'Pending setup / approval' }}</td><td class="muted small">{{ $source['pending'] ?? (($source['kind'] ?? '') === 'business' ? 'Public business candidates; verify service fit, country and contact permission.' : (($source['kind'] ?? '') === 'search' ? 'Public search snippets and links; unverified requests, incomplete indexing and dates.' : 'Recent relevant contract/freelance jobs; check eligibility.')) }}</td></tr>@endforeach
</tbody></table></div>
<h3>Recent collection runs</h3><div class="table-wrap"><table><thead><tr><th>Time (UTC)</th><th>Source</th><th>Status</th><th>Fetched / matched</th><th>Added / duplicates / rejected</th></tr></thead><tbody>
@forelse($collectionRuns as $run)<tr><td>{{ $run->created_at->format('d M H:i') }}</td><td>{{ $run->source }}</td><td>{{ $run->status }} {{ $run->error_code }}</td><td>{{ $run->fetched }} / {{ $run->matched }}</td><td>{{ $run->imported }} / {{ $run->duplicates }} / {{ $run->rejected }}</td></tr>@empty<tr><td colspan="5" class="muted">No collection runs yet.</td></tr>@endforelse
</tbody></table></div></details>
@endif
@endsection

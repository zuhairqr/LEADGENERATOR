@extends('layout')
@section('title', $lead->title)
@section('content')
@php
    $canEdit = request()->attributes->get('operator_role') !== 'viewer';
    $isBusiness = $lead->kind === 'business';
    $country = $isBusiness ? $lead->business?->country_code : $lead->country_code;
@endphp
<a class="muted" href="{{ route('dashboard') }}#lead-inbox">← Back to my leads</a>
<div class="heading page-heading"><div><div class="eyebrow">{{ $lead->source }}</div><h1>{{ $lead->title }}</h1><a href="{{ $lead->url }}" target="_blank" rel="{{ $lead->source === 'remoteok' ? 'noopener noreferrer' : 'noopener noreferrer nofollow' }}">{{ $lead->kind === 'business' ? 'View original source' : 'View original listing' }} ↗</a></div><div class="task-actions heading-action"><span class="status-badge" data-status="{{ $lead->status }}">{{ ucfirst($lead->status) }}</span><a class="button-link" href="#lead-contact">Contact options</a></div></div>
<div class="lead-checks" aria-label="Lead review summary">
    <div><span>Country</span><strong>{{ $country ?? 'Unconfirmed' }}</strong>@if(!$countryReady)<small>Outreach on hold</small>@endif</div>
    @if($isBusiness)<div><span>Website check</span><strong>{{ $websiteChecked ? 'Evidence available' : 'Check needed' }}</strong></div>@endif
    <div><span>Contact permission</span><strong>{{ $lead->contact_permission_at ? 'Verified' : 'Pending' }}</strong></div>
    <div><span>Next task</span><strong>{{ $lead->next_action_at ? $lead->next_action_at->timezone('Asia/Karachi')->format('d M, H:i').' PKT' : 'No pending task' }}</strong></div>
</div>
<nav class="filter-links" aria-label="On this lead">
    <a href="#lead-overview">Lead details</a>
    @if($lead->kind === 'business')<a href="#lead-business">Website and country</a>@endif
    <a href="#lead-proposal">Proposal</a>
    <a href="#lead-contact">Contact and email</a>
    <a href="#lead-notes">Notes and tasks</a>
</nav>
@include('lead-contact')
<div class="columns">
<section class="panel" id="lead-overview"><h2>{{ $lead->kind === 'business' ? 'About this business' : 'Project brief' }}</h2><p class="prewrap">{{ $lead->description }}</p>
<hr><h3>Budget & timing</h3><p>{{ $lead->currency }} {{ $lead->budget_min ?? 'Unknown' }} – {{ $lead->budget_max ?? 'Unknown' }}</p><p class="muted small">Published: {{ $lead->published_at?->format($lead->source === 'public-web' && $lead->appliesOnSource() ? 'd M Y' : 'd M Y H:i \\U\\T\\C') ?? 'Unknown' }}</p>
@if($lead->listing_expires_at)<p class="muted small">{{ $lead->listing_expires_at->isFuture() ? 'Open in Lead Inbox' : 'Past inbox cutoff · check the original notice for updates' }}</p>@endif
<p class="muted small">Collected: {{ $lead->created_at->format('d M Y H:i') }} UTC</p>
<hr><h3>{{ $lead->kind==='business'?'Website evidence fit':'Keyword match' }}: {{ $lead->match_score }}/100</h3><p class="muted">{{ $lead->match_reason }}</p>
@if($lead->ai_score !== null)<h3>AI assessment: {{ $lead->ai_score }}/100</h3><p class="prewrap muted">{{ $lead->ai_reason }}</p><p class="muted small">{{ $lead->ai_model }} · {{ $lead->drafted_at?->format('d M Y H:i') }} UTC · Not a probability</p>@endif
</section>
<section class="panel" id="lead-proposal"><div class="section-heading"><h2>Proposal draft</h2><span class="tag">DRAFT ONLY</span></div>
<p class="muted">Check claims, scope, pricing and platform rules before using this proposal.</p>
@if($canEdit)
<form method="post" action="{{ route('leads.update', $lead) }}" class="stack">@csrf @method('PATCH')
<label>Proposal<textarea name="proposal" rows="17" maxlength="12000" placeholder="Write your proposal, or request an AI draft below.">{{ old('proposal', $lead->proposal) }}</textarea></label>
<label>Lead status<select name="status">@foreach(\App\Models\Lead::STATUSES as $status)<option value="{{ $status }}" @selected(old('status', $lead->status) === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
<button>Save changes</button><p class="muted small">“Approved” is an internal label. Saving does not contact anyone.</p>
</form>
<hr>
@if($lead->appliesOnSource())
    <p class="muted">Use your saved proposal when applying on the original listing.</p>
@elseif(!$lead->proposal && !$countryReady)
    <p class="muted">AI drafting is on hold until the lead has an eligible country. <a href="{{ $isBusiness ? '#lead-business' : '#lead-notes' }}">See country review</a>.</p>
@elseif(!$lead->proposal && $isBusiness && !$websiteChecked)
    <p class="muted">A proposal needs evidence from the current website. <a href="#lead-business">Go to the website check</a>.</p>
@elseif(!$lead->proposal)
<form method="post" action="{{ route('leads.draft', $lead) }}" class="stack">@csrf
<label class="check"><input type="checkbox" name="ai_consent" value="1" required><span>I am allowed to share this listing's title, description and budget with OpenAI for analysis. API charges may apply. I have removed sensitive information from the source data.</span></label>
<button class="secondary">Create an AI draft</button>
<p class="muted small">Save any edits first. Drafting uses your configured verified business profile.</p>
</form>
@endif
@else
    <p class="prewrap">{{ $lead->proposal ?: 'No proposal saved yet.' }}</p>
@endif
<p><a href="{{ route('outreach.index', ['lead' => $lead->id]) }}">View emails for this lead →</a></p>
</section></div>
@if($lead->kind==='business')@include('business-detail')@endif
@include('crm-detail')
@endsection

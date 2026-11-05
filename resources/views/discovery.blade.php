@extends('layout')
@section('title', 'Find leads')
@section('content')
@php
    $canEdit = request()->attributes->get('operator_role') !== 'viewer';
    $isAdmin = in_array(request()->attributes->get('operator_role'), ['owner', 'admin'], true);
@endphp
<div class="heading page-heading">
    <div>
        <div class="eyebrow">STEP 1</div>
        <h1>Find leads</h1>
        <p class="muted">Find businesses you can reach through their public email or website contact page.</p>
    </div>
    <a href="{{ route('dashboard') }}#lead-inbox">Back to my leads</a>
</div>
@if($canEdit)
<section class="panel">
    <h2>Search for leads online</h2>
    @if(!$sourceApproved)
        <p class="notice">Online search needs to be enabled by a workspace admin. You can add businesses manually or upload a list below.</p>
    @endif
    <form method="post" action="{{ route('discovery.search') }}" class="stack">@csrf
        <div class="columns">
            <label>Type of business or service<input name="niche" maxlength="100" required placeholder="e.g. Dental clinics" value="{{ old('niche') }}"></label>
            <label>City or country (optional)<input name="location" maxlength="100" placeholder="e.g. Toronto or Canada" value="{{ old('location') }}"></label>
        </div>
        <label>What are you looking for?
            <select name="search_mode">
                <option value="business" @selected(old('search_mode', 'business') === 'business')>Businesses with their own websites</option>
                <option value="service-request" @selected(old('search_mode') === 'service-request')>People asking for services I offer</option>
            </select>
        </label>
        <div class="task-actions">
            <button @disabled(!$sourceApproved)>Search for leads</button>
            <span class="muted small">Up to four searches every 24 hours. Review results before saving them.</span>
        </div>
    </form>
</section>
@endif
@if(session('business_search_quality'))
<section class="panel" id="search-results" aria-labelledby="search-results-title">
    <div class="section-heading">
        <div>
            <div class="eyebrow">SEARCH RESULTS</div>
            <h2 id="search-results-title">{{ $results ? 'Review before saving' : 'No matching businesses found' }}</h2>
        </div>
        <span class="tag">{{ session('business_search_quality.target') }}</span>
    </div>
    <p class="muted">{{ count($results) }} to review · {{ session('business_search_quality.filtered') }} filtered · {{ session('business_search_quality.duplicate_domains') }} duplicate domains</p>
    @if(session('business_search_quality.filter_reasons'))
        <details class="filter-details">
            <summary>Why results were filtered</summary>
            <div class="tags">@foreach(session('business_search_quality.filter_reasons', []) as $reason => $count)<span class="tag">{{ ucfirst(strtolower(str_replace('_', ' ', $reason))) }} · {{ $count }}</span>@endforeach</div>
        </details>
    @endif
    @foreach($results as $i => $result)
        <article class="search-result">
            <div class="section-heading">
                <h3>{{ $result['name'] }}</h3>
                @if(isset($result['_discovery_evidence']['score']))<span class="tag">Search match {{ $result['_discovery_evidence']['score'] }}/100</span>@endif
            </div>
            <p class="muted">{{ $result['description'] }}</p>
            <div class="task-actions">
                <a href="{{ $result['source_url'] ?? $result['website'] }}" target="_blank" rel="noopener noreferrer">View original result ↗</a>
                <span class="muted small">{{ !empty($result['_discovery_evidence']['buyer_request']) ? 'Possible service request' : 'Business prospect · interest unconfirmed' }}</span>
            </div>
            @if(!empty($result['_discovery_evidence']['signals']))
                <p class="small muted">{{ implode(' · ', array_map(fn ($signal) => ucfirst(strtolower(str_replace('_', ' ', $signal))), $result['_discovery_evidence']['signals'])) }}</p>
            @endif
            @if($canEdit)
                <form method="post" action="{{ route('discovery.import') }}" class="task-actions">@csrf
                    <input type="hidden" name="candidate" value="{{ $i }}">
                    <input type="hidden" name="search_batch" value="{{ session('business_search_batch') }}">
                    <label class="check"><input type="checkbox" name="permission" value="1" required><span>I reviewed this result and have permission to save it.</span></label>
                    <button class="secondary">Save lead</button>
                </form>
            @endif
        </article>
    @endforeach
</section>
@endif
@if($canEdit)
<div class="columns discovery-options">
    <details class="panel workspace-details" @if(old('name') !== null || $errors->has('business')) open @endif>
        <summary>Add one business</summary>
        <p class="muted">Enter the business name and either its website or the page where you found it.</p>
        <form method="post" action="{{ route('discovery.import') }}" class="stack">@csrf
            <label>Business name<input name="name" maxlength="200" required value="{{ old('name') }}"></label>
            <label>Business website<input name="website" type="url" maxlength="2048" placeholder="https://example.com" value="{{ old('website') }}"></label>
            <label>Link to the original source<input name="source_url" type="url" maxlength="2048" value="{{ old('source_url') }}"></label>
            <details class="filter-details" @if($errors->hasAny(['niche', 'location', 'country', 'email', 'phone', 'description']) && old('name') !== null) open @endif>
                <summary>Optional business details</summary>
                <div class="stack">
                    <label>Type of business<input name="niche" maxlength="120" value="{{ old('niche') }}"></label>
                    <label>Location<input name="location" maxlength="200" value="{{ old('location') }}"></label>
                    <label>Country code<input name="country" maxlength="80" placeholder="e.g. US, GB, CA or AE" value="{{ old('country') }}"></label>
                    <label>Public business email (unverified)<input name="email" type="email" maxlength="254" value="{{ old('email') }}"></label>
                    <label>Phone<input name="phone" maxlength="80" value="{{ old('phone') }}"></label>
                    <label>Notes about the source<textarea name="description" maxlength="8000">{{ old('description') }}</textarea></label>
                </div>
            </details>
            <label class="check"><input type="checkbox" name="permission" value="1" required><span>I have permission to add this business information.</span></label>
            <div><button>Save business</button></div>
        </form>
    </details>
    <details class="panel workspace-details" @if($errors->has('file')) open @endif>
        <summary>Upload a business list (CSV)</summary>
        <p class="muted">Use the template to add up to 500 businesses in one file (1 MB max). Each row needs a name and a website or source link. Duplicate domains are merged.</p>
        <p><a href="{{ route('discovery.template') }}">Download the CSV template</a></p>
        <form method="post" action="{{ route('discovery.csv') }}" enctype="multipart/form-data" class="stack">@csrf
            <label>Choose a CSV file<input type="file" name="file" accept=".csv,text/csv" required></label>
            <label class="check"><input type="checkbox" name="permission" value="1" required><span>I have permission to add these business records.</span></label>
            <div><button>Upload list</button></div>
        </form>
    </details>
</div>
@endif
<section class="panel"><h2>Saved businesses</h2><p class="muted">Open a business to confirm its country, check its website and review contact details.</p><div class="table-wrap"><table><thead><tr><th>Company</th><th>Niche / location</th><th>Evidence fit</th><th>Status</th></tr></thead><tbody>@forelse($businesses as $lead)<tr><td><a href="{{ route('leads.show',$lead) }}">{{ $lead->title }}</a><div class="small muted">{{ $lead->business?->domain ?? 'Website needed' }}</div></td><td>{{ $lead->business?->niche }} · {{ $lead->business?->location }}</td><td>{{ $lead->match_score }}/100</td><td>{{ ucfirst($lead->status) }}</td></tr>@empty<tr><td colspan="4">No businesses have been saved yet.</td></tr>@endforelse</tbody></table></div>{{ $businesses->links() }}</section>
@if($isAdmin)
<details class="panel workspace-details" @if($errors->hasAny(['niches', 'locations', 'excluded_countries', 'search_mode'])) open @endif>
    <summary>Automatic search settings</summary>
    <p class="muted">Choose the business types and locations to search on a schedule.</p>
    <form method="post" action="{{ route('discovery.settings') }}" class="stack">
        @csrf @method('PUT')
        <label>Search for<select name="search_mode">
            <option value="business" @selected(old('search_mode', $settings['search_mode'] ?? 'service-request') === 'business')>Businesses with their own websites</option>
            <option value="service-request" @selected(old('search_mode', $settings['search_mode'] ?? 'service-request') === 'service-request')>Public requests for my services</option>
        </select></label>
        <label>Business types to search for<textarea name="niches" maxlength="1500" required>{{ implode("\n", $settings['niches'] ?? []) }}</textarea></label>
        <label>Locations<textarea name="locations" maxlength="1500">{{ implode("\n", $settings['locations'] ?? []) }}</textarea></label>
        <label>Additional countries to skip (two-letter codes)<input name="excluded_countries" value="{{ implode(', ', array_diff($settings['excluded_countries'] ?? [], \App\Services\CountryPolicy::REQUIRED_EXCLUSIONS)) }}"></label>
        <p class="muted small">India and Pakistan are always excluded.</p>
        <label class="check"><input type="checkbox" name="enabled" value="1" @checked($settings['enabled'] ?? false)><span>Discover and import public prospects every six hours.</span></label>
        <label class="check"><input type="checkbox" name="auto_audit" value="1" @checked($settings['auto_audit'] ?? false)><span>Check business websites for public contacts and service opportunities.</span></label>
        <button class="secondary">Save search settings</button>
    </form>
</details>
@endif
@endsection

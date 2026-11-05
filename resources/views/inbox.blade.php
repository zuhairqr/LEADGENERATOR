@extends('layout')
@section('title', 'Replies')
@section('content')
@php
    $isAdmin = in_array(request()->attributes->get('operator_role'), ['owner', 'admin'], true);
    $canEdit = request()->attributes->get('operator_role') !== 'viewer';
    $gmailVerified = !empty($health['connected']) && !empty($health['sender_matches']);
@endphp
<div class="heading page-heading">
    <div>
        <div class="eyebrow">STEP 4</div>
        <h1>Replies</h1>
        <p class="muted">Read responses to your lead emails and open the related lead.</p>
    </div>
    @if($canEdit)
        <form method="post" action="{{ route('inbox.sync') }}">@csrf<button>Check for new replies</button></form>
    @endif
</div>
<details class="panel workspace-details" @if(!$gmailVerified || !empty($sync['error_code'])) open @endif>
    <summary>{{ $gmailVerified ? 'Gmail account verified' : 'Gmail connection needs attention' }}</summary>
    <p class="muted">Last check · {{ $sync['checked_at'] ?? 'Never checked' }} @if(!empty($sync['ok'])) · Completed @endif</p>
    @if(!empty($health['error_code']) || !empty($sync['error_code']))
        <p class="muted small">Connection details · {{ $health['error_code'] ?? $sync['error_code'] }}</p>
    @endif
    @if($isAdmin)
        <div class="task-actions">
            <form method="post" action="{{ route('inbox.verify') }}">@csrf<button class="secondary">Check Gmail access</button></form>
            <form method="post" action="{{ route('inbox.connect') }}">@csrf<button class="secondary">Finish Gmail setup</button></form>
            <a href="{{ route('mail-test.index') }}">Test with my own inbox</a>
        </div>
    @elseif(!$gmailVerified)
        <p class="muted">Ask a workspace admin to connect Gmail.</p>
    @endif
</details>
@forelse($events as $event)
    <section class="panel">
        <div class="section-heading">
            <h2>@if($event->lead_id)<a href="{{ route('leads.show', $event->lead_id) }}">{{ $event->subject ?: 'Matched reply' }}</a>@else{{ $event->subject ?: 'Matched reply' }}@endif</h2>
            <span class="tag">{{ ucfirst(str_replace('_', ' ', $event->intent)) }}</span>
        </div>
        <p class="small muted">{{ $event->sender }} · {{ $event->created_at->timezone('Asia/Karachi')->format('d M Y H:i') }} PKT</p>
        <p class="prewrap">{{ $event->excerpt }}</p>
        @if($canEdit)
            <form method="post" action="{{ route('inbox.classify', $event) }}" class="filters">@csrf @method('PUT')
                <label>How did they respond?
                    <select name="intent">
                        @foreach(['interested', 'not_interested', 'question', 'out_of_office', 'unsubscribe', 'bounce', 'reply'] as $intent)
                            <option value="{{ $intent }}" @selected($event->intent === $intent)>{{ ucfirst(str_replace('_', ' ', $intent)) }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="secondary">Update reply label</button>
            </form>
            <p class="small muted">Labels for opt-outs and bounces stop follow-ups. Updating a label leaves stopped follow-ups paused.</p>
        @endif
    </section>
@empty
    <section class="panel empty">
        <h2>No matched replies yet</h2>
        <p class="muted">{{ $gmailVerified ? 'Replies appear here when they match an email you sent to a lead.' : 'Once Gmail is connected, replies to your lead emails can appear here.' }}</p>
        <a href="{{ route('outreach.index', ['status' => 'sent']) }}">View sent emails</a>
    </section>
@endforelse
{{ $events->links() }}
@endsection

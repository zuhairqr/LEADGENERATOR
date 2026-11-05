@extends('layout')
@section('title', 'Email drafts')
@section('content')
@php
    $isAdmin = in_array(request()->attributes->get('operator_role'), ['owner', 'admin'], true);
    $canEdit = request()->attributes->get('operator_role') !== 'viewer';
@endphp
<div class="heading page-heading">
    <div>
        <div class="eyebrow">STEP 3</div>
        <h1>Email drafts & follow-ups</h1>
        <p class="muted">Review the recipient and message before approving an email. Follow-ups also require approval.</p>
    </div>
    @if($isAdmin)<a class="heading-action" href="{{ route('automation.edit') }}">Email settings</a>@endif
</div>
@if($leadFilter)
    <div class="scope-filter">
        <span>Showing emails for <strong>{{ $leadFilter->title }}</strong></span>
        <a href="{{ route('outreach.index', ['status' => $status]) }}">Clear lead filter</a>
    </div>
@endif
<nav class="filter-links" aria-label="Filter emails by status">
    <a href="{{ route('outreach.index', ['lead' => $leadFilter?->id]) }}" @if($status === null) aria-current="page" @endif>All emails</a>
    @foreach($filters as $value => $label)
        <a href="{{ route('outreach.index', ['status' => $value, 'lead' => $leadFilter?->id]) }}" @if($status === $value) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
@forelse($messages as $message)<section class="panel" id="message-{{ $message->id }}" style="margin-bottom:24px">
@if($message->lead->kind==='connection_test')<p class="tag">TECHNICAL OWN-INBOX TEST · fixed template · <a href="{{ route('mail-test.index') }}">Test status</a></p>@endif
<div class="section-heading"><h2>{{ $message->kind==='followup' ? 'Follow-up #'.$message->sequence : 'Initial email' }} · <a href="{{ route('leads.show',$message->lead) }}">{{ $message->lead->title }}</a></h2><span class="status-badge" data-status="{{ $message->status }}">{{ $filters[$message->status] ?? ucfirst(str_replace('_', ' ', $message->status)) }}</span></div>
<p class="small muted">To: {{ $message->recipient ?: 'Add a verified client email on the lead' }} · From: {{ $message->status==='draft' ? ($sender['from_name'] ?? '') : $message->from_name }} &lt;{{ $message->status==='draft' ? ($sender['from_email'] ?? 'not configured') : $message->from_email }}&gt; · Revision {{ $message->revision }}</p>
@if($canEdit && in_array($message->status,['draft','approved','failed']) && $message->lead->kind!=='connection_test')
<form method="post" action="{{ route('outreach.edit',$message) }}" class="stack">@csrf @method('PUT')<input type="hidden" name="revision" value="{{ $message->revision }}">
<label>Subject<input name="subject" maxlength="200" required value="{{ $message->subject }}"></label><label>Email body<textarea name="body" rows="9" maxlength="12000" required>{{ $message->body }}</textarea></label><button class="secondary">Save edits — requires fresh approval</button></form>
@else<h3>{{ $message->subject }}</h3><p class="prewrap">{{ $message->body }}</p>@endif
@if($isAdmin && $message->status==='draft')
@if(!$message->recipient)<p class="notice"><a href="{{ route('leads.show',$message->lead) }}#lead-contact">Add and verify the recipient on the lead</a> before approving this email.</p>@endif
@if(empty($sender['from_email']))<p class="notice"><a href="{{ route('automation.edit') }}">Set up the sending email address</a> to enable approval.</p>@endif
<hr><form method="post" action="{{ route('outreach.approve',$message) }}" class="stack">@csrf<input type="hidden" name="digest" value="{{ $message->digest($sender) }}">
<label class="check"><input type="checkbox" name="approval" value="1" required><span>I reviewed this exact subject, body, recipient and sender. I approve sending this email to {{ $message->recipient ?: '(recipient missing)' }}. Changes invalidate this approval.</span></label><button @disabled(!$message->recipient || empty($sender['from_email']))>Approve and queue email</button></form>@endif
@if($canEdit && in_array($message->status,['draft','approved']))<form method="post" action="{{ route('outreach.cancel',$message) }}" style="margin-top:12px">@csrf<button class="secondary">Cancel message</button></form>@endif
@if($message->status==='sent')<p class="muted small">Accepted by your email provider: {{ $message->sent_at }} UTC.
@if($message->followup_due_at) Follow-up due {{ $message->followup_due_at }} UTC. @endif
Inbox delivery is unconfirmed.</p>@if($canEdit && $message->lead->kind!=='connection_test')<form method="post" action="{{ route('outreach.followup',$message) }}">@csrf<button class="secondary">Prepare follow-up draft for review</button></form>@endif @endif
@if($message->status==='delivery_unknown' || $message->status==='sending')<p class="error">Delivery is not confirmed. No automatic retry will occur. Check your Sent folder/provider before taking further action.</p>@endif
@if($isAdmin && $message->status==='delivery_unknown')<form method="post" action="{{ route('outreach.resolve',$message) }}" class="stack">@csrf<label class="check"><input type="checkbox" name="checked_provider" value="1" required><span>I checked the sending account's Sent folder/provider and confirmed the result below.</span></label><label>Verified result<select name="resolution"><option value="confirmed_sent">It was sent — record manually</option><option value="confirmed_not_sent">It was NOT sent — return to draft for fresh approval</option></select></label><button class="secondary">Record my provider check</button></form>@endif
@if($message->lead->replied_at)<p class="notice">Reply recorded. Further messages are stopped.</p>@endif
@if($message->lead->unsubscribed_at)<p class="notice">Opt-out recorded. Further messages are stopped.</p>@endif
@if($message->error_code)<p class="muted small">{{ $message->error_code }}</p>@endif
</section>@empty<section class="panel empty">
    <h2>{{ $status === 'draft' ? 'No drafts to review' : ($status === null ? 'No emails yet' : 'No emails with this status') }}</h2>
    @if($status === null || $status === 'draft')
        <p class="muted">Open a lead to review its details and prepare an email draft.</p>
        @if($leadFilter)<a class="button-link" href="{{ route('leads.show', $leadFilter) }}#lead-proposal">Back to this lead</a>
        @else<a class="button-link" href="{{ route('dashboard') }}#lead-inbox">Open my leads</a>@endif
    @else
        <a href="{{ route('outreach.index', ['lead' => $leadFilter?->id]) }}">View all emails</a>
    @endif
</section>@endforelse
<div class="pagination">{{ $messages->links() }}</div>
@endsection

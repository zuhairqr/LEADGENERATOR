@extends('layout')
@section('title','Skills & automation')
@section('content')
<div class="heading"><div><div class="eyebrow">YOUR SALES PIPELINE</div><h1>Skills, AI & email</h1><p class="muted">Search → qualify → personalized drafts → your approval → sending → reply and follow-up tracking.</p></div><a href="{{ route('outreach.index') }}">Review email queue →</a></div>
<div class="columns"><section class="panel"><h2>Targeting and verified business profile</h2>
<form method="post" action="{{ route('automation.update') }}" class="stack">@csrf @method('PUT')
<label>Skills and keywords<textarea name="skills_text" rows="8" required>{{ $skillText }}</textarea><small class="muted">One skill per line, for example: Python: python, django, fastapi. Up to 20 skills.</small></label>
<label>Exclude keywords<input name="excluded_text" maxlength="1000" value="{{ implode(', ',$profile['excluded_keywords'] ?? []) }}"></label>
<label>Location keywords <small class="muted">(blank = any location)</small><input name="locations_text" maxlength="800" value="{{ implode(', ',$profile['locations'] ?? []) }}"></label>
<div class="columns"><label>Minimum keyword match<input type="number" name="minimum_score" min="0" max="100" value="{{ $profile['minimum_score'] ?? 52 }}" required></label><label>Maximum listing age in days<input type="number" name="max_age_days" min="1" max="90" value="{{ $profile['max_age_days'] ?? 30 }}" required></label></div>
<fieldset><legend>Sources to search</legend><input type="hidden" name="enabled_sources[]" value="">
@foreach($sources as $id=>$source)<label class="check"><input type="checkbox" name="enabled_sources[]" value="{{ $id }}" @checked(in_array($id,$profile['enabled_sources'] ?? array_keys($sources)))><span>{{ $source['label'] ?? $id }}{{ !($source['permitted'] ?? false) ? ' — awaiting source approval' : '' }}</span></label>@endforeach</fieldset>
<label>Business / sender business name<input name="business_name" maxlength="150" value="{{ $profile['business_name'] ?? '' }}"></label>
<label>Verified business facts<textarea name="verified_facts" rows="7" maxlength="6000" placeholder="Your actual services, relevant experience, approved portfolio links and facts AI may use.">{{ $profile['verified_facts'] ?? '' }}</textarea></label>
<label>Email signature<textarea name="signature" rows="3" maxlength="1000">{{ $profile['signature'] ?? '' }}</textarea></label>
<label>Draft language<input name="language" maxlength="80" value="{{ $profile['language'] ?? 'English' }}" required></label>
<label>Model on the saved n8n OpenAI connection<input name="ai_model" maxlength="100" value="{{ $profile['ai_model'] ?? 'gpt-6.1-sol' }}" required></label>
<label>n8n workflow AI credential name<input name="ai_credential_name" maxlength="150" value="{{ $profile['ai_credential_name'] ?? '' }}" placeholder="Exact OpenAI credential name, if more than one exists"></label>
<p class="muted small">An n8n Assistant provider connection is separate from a workflow credential. Add an OpenAI connection under n8n Credentials, then run the connection setup command below. The secret stays inside n8n.</p>
<div class="columns"><label>AI attempts per rolling 24 hours<input type="number" name="daily_ai_limit" min="1" max="25" value="{{ $profile['daily_ai_limit'] ?? 5 }}" required></label><label>Follow-up after days<input type="number" name="followup_days" min="1" max="30" value="{{ $profile['followup_days'] ?? 3 }}" required></label></div>
<label>Maximum follow-ups per lead<input type="number" name="max_followups" min="0" max="3" value="{{ $profile['max_followups'] ?? 2 }}" required></label>
<label class="check"><input type="checkbox" name="profile_verified" value="1" @checked($profile['profile_verified'] ?? false)><span>These are verified facts about my business; AI must not invent claims.</span></label>
<label class="check"><input type="checkbox" name="ai_consent" value="1" @checked($profile['ai_consent'] ?? false)><span>I approve sharing permitted listing text and these business facts with the saved AI provider. API charges may apply; I have removed private data.</span></label>
<label class="check"><input type="checkbox" name="auto_draft" value="1" @checked($profile['auto_draft'] ?? false)><span>Automatically qualify relevant leads and prepare proposal/email and due follow-up drafts. Every message still needs my separate approval.</span></label>
<button>Save skills & automation</button></form></section>
<section class="panel"><h2>Your email connection</h2><p class="muted">Passwords stay encrypted locally. Blank password fields preserve an existing password.</p>
<form method="post" action="{{ route('automation.mail') }}" class="stack">@csrf @method('PUT')
<label>Provider<select name="provider"><option value="smtp" @selected(($mail['provider'] ?? 'smtp')==='smtp')>SMTP — Gmail app password / hosting</option><option value="gmail" @selected(($mail['provider'] ?? '')==='gmail')>Gmail / Workspace OAuth via n8n</option></select></label>
<label>From email<input type="email" name="from_email" maxlength="254" value="{{ $mail['from_email'] ?? '' }}" required></label>
<label>From name<input name="from_name" maxlength="150" value="{{ $mail['from_name'] ?? '' }}" required></label>
<label>n8n Gmail credential name (OAuth only)<input name="gmail_credential_name" maxlength="150" value="{{ $mail['gmail_credential_name'] ?? '' }}" placeholder="Exact name from your n8n Credentials page"></label>
<label>n8n IMAP credential name (optional inbox tracking)<input name="imap_credential_name" maxlength="150" value="{{ $mail['imap_credential_name'] ?? '' }}" placeholder="Exact name of your own inbox credential"></label>
<label>SMTP host<input name="smtp_host" maxlength="253" value="{{ $smtp['host'] ?? '' }}" placeholder="smtp.gmail.com"></label>
<label>SMTP port<select name="smtp_port"><option value="587" @selected(($smtp['port'] ?? 587)==587)>587 — STARTTLS</option><option value="465" @selected(($smtp['port'] ?? 587)==465)>465 — TLS</option></select></label>
<label>SMTP security<select name="smtp_security"><option value="starttls" @selected(($smtp['security'] ?? 'starttls')==='starttls')>STARTTLS required</option><option value="smtps" @selected(($smtp['security'] ?? '')==='smtps')>TLS from connection start</option></select></label>
<label>SMTP username<input name="smtp_username" maxlength="254" autocomplete="off" value="{{ $smtp['username'] ?? '' }}"></label>
<label>SMTP password / app password<input type="password" name="smtp_password" maxlength="1024" autocomplete="new-password"><small class="muted">{{ $hasSmtpPassword ? 'A password is stored; it will not be displayed.' : 'No password stored.' }}</small></label>
<label class="check"><input type="checkbox" name="sender_verified" value="1" @checked($mail['sender_verified'] ?? false)><span>I own/control this sending account and have verified the sender address.</span></label>
<button>Save email connection privately</button></form>
@if(($mail['provider'] ?? 'smtp')==='smtp')
<hr><h3>SMTP connection check</h3>
@if(!empty($smtpHealth['authenticated']) && !empty($smtpHealth['tls']) && !empty($smtpHealth['sender_matches_authenticated_account']))
<p class="notice">Authentication over TLS passed at {{ \Carbon\Carbon::parse($smtpHealth['checked_at'])->timezone('Asia/Karachi')->format('d M Y H:i') }} PKT. No email was sent. This does not establish inbox delivery.</p>
@elseif(!empty($smtpHealth['error_code']))
<p class="error">Connection check: {{ $smtpHealth['error_code'] }}. No email was sent.</p>
@else<p class="muted small">Save your credentials, then test the saved connection.</p>@endif
<form method="post" action="{{ route('automation.mail.test') }}">@csrf<button class="secondary">Check saved SMTP — no email sent</button></form>
<p class="muted small">For Gmail SMTP, use a Google App Password with 2-Step Verification. <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener noreferrer">Open Google App Passwords ↗</a>. Enter it only in the private password field above.</p>
@endif
<hr><h3>Gmail OAuth and automatic reply tracking</h3><p>Connect your Gmail/Workspace or IMAP inbox in n8n's private credential form. An app access key is not required for SMTP. Inbox credentials are separate from public source permissions.</p>
<p><a href="http://localhost:5679/home/credentials" target="_blank" rel="noopener noreferrer">Open n8n email credentials ↗</a></p>
<p class="muted small">After saving a Gmail/IMAP credential name, run <code>powershell -File deployment/n8n/setup-sales.ps1</code> from this project to connect it. AI bridge: {{ !empty($bridges['ai_ready']) ? 'published' : 'pending' }} · Gmail: {{ !empty($bridges['gmail_ready']) ? 'published; verify your account before approving mail' : 'not connected' }} · Inbox tracking: {{ !empty($bridges['inbox_ready']) ? 'published' : 'not connected' }}.</p>
<p class="muted small">Until inbox sync is connected, record replies, bounces and opt-outs manually on the lead. These outcomes stop future messages and follow-ups. A provider-accepted email is not proof of inbox delivery.</p>
</section></div>
@endsection

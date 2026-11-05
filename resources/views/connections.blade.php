@extends('layout')
@section('title', 'Connections')
@section('content')
<a href="{{ route('dashboard') }}">← Lead inbox</a>
<div class="heading"><div><div class="eyebrow">SOURCE CONNECTIONS</div><h1>Facebook & Instagram</h1><p class="muted">Add your account details here. You can save part of the setup and return later.</p></div><span class="pill">Private intake: pending connection</span></div>
<div class="columns">
<section class="panel"><h2>Your account details</h2>
<form method="post" action="{{ route('connections.update') }}" class="stack">@csrf @method('PUT')
<label>Facebook Page URL<input type="url" name="facebook_page_url" maxlength="2048" placeholder="https://www.facebook.com/your-page" value="{{ $details['facebook_page_url'] ?? '' }}"></label>
<label>Facebook Page ID <span class="muted small">(optional until available)</span><input name="facebook_page_id" inputmode="numeric" maxlength="40" value="{{ $details['facebook_page_id'] ?? '' }}"></label>
<label>Instagram business / creator username<input name="instagram_username" maxlength="31" placeholder="@your_business" value="{{ $details['instagram_username'] ?? '' }}"></label>
<label>Instagram professional account ID <span class="muted small">(optional until available)</span><input name="instagram_account_id" inputmode="numeric" maxlength="40" value="{{ $details['instagram_account_id'] ?? '' }}"></label>
<label>Meta App ID<input name="meta_app_id" inputmode="numeric" maxlength="40" value="{{ $details['meta_app_id'] ?? '' }}"></label>
<label>Public HTTPS webhook URL<input type="url" name="webhook_url" maxlength="2048" placeholder="https://your-domain/webhook/..." value="{{ $details['webhook_url'] ?? '' }}"></label>
<p class="muted small">Use the webhook URL supplied by your n8n Meta trigger after your public HTTPS address is configured. A localhost URL cannot receive Meta events.</p>
<label>Saved Meta credential name in n8n <span class="muted small">(optional)</span><input name="n8n_credential_name" maxlength="120" value="{{ $details['n8n_credential_name'] ?? '' }}"></label>
<button>Save connection details</button>
</form></section>
<section class="panel"><h2>Connect privately in n8n</h2>
<p>Enter your real Meta access token and app secret in n8n's credential form. This page only saves account identifiers and URLs.</p>
<p><a href="http://localhost:5679/home/credentials" target="_blank" rel="noopener noreferrer">Open n8n credentials ↗</a></p>
<ol><li>Save the Page and Instagram details you already have.</li><li>Add your Meta app connection in n8n and grant the read permissions needed for your own accounts.</li><li>Configure the public HTTPS webhook and verify a signed test event.</li><li>Complete the event mapping before enabling private intake.</li></ol>
<p class="muted small">Saving these fields does not authorize Meta, test a token, activate private collection, or send messages. The public-source collector continues independently.</p>
<p><a href="https://docs.n8n.io/integrations/builtin/credentials/facebookapp/" target="_blank" rel="noopener noreferrer">Meta credential setup guide ↗</a></p>
</section></div>
@endsection

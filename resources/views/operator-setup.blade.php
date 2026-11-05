@extends('layout')
@section('title', 'Set up your account')
@section('content')
<section class="login panel"><div class="eyebrow">FIRST-TIME LOCAL SETUP</div>
<h1>Create your owner account.</h1><p class="muted">Choose your own login for Lead Generator. After signing in, open Connections to add Facebook and Instagram details.</p>
<form method="post" action="{{ route('operator.setup.store') }}" class="stack">@csrf
<label>Email<input type="email" name="email" autocomplete="username" maxlength="254" required></label>
<label>Password<input type="password" name="password" autocomplete="new-password" minlength="12" maxlength="512" required></label>
<label>Confirm password<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" maxlength="512" required></label>
<button>Create owner account</button></form>
<p class="muted small">Available only on this computer before the first owner is configured.</p></section>
@endsection

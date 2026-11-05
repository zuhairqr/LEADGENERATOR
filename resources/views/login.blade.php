@extends('layout')
@section('title', 'Sign in')
@section('content')
<section class="login panel">
    <div class="eyebrow">YOUR NEXT PROJECT STARTS HERE</div>
    <h1>Welcome back.</h1>
    <p class="muted">Sign in to review leads and prepare your next proposal.</p>
    <form method="post" action="{{ route('login') }}" class="stack">@csrf
        <label>Email<input type="email" name="email" autocomplete="username" required value="{{ old('email') }}"></label>
        <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
        <button>Sign in →</button>
    </form>
    @if(app()->environment('local') && (string) config('leadgen.admin_email') === '' && (string) config('leadgen.admin_password_hash') === '')
    <p><a href="{{ route('operator.setup') }}">Create the first owner account →</a></p>
    @endif
    <p class="muted small">Private operator dashboard. No public registration.</p>
    @if(app()->environment('local'))<p class="muted small">Forgot your password? On this computer, open <code>deployment/reset-local-password.ps1</code> from the project folder. Choose a new password privately; your existing email and project data stay the same.</p>@endif
</section>
@endsection

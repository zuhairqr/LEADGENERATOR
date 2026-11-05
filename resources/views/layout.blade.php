<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#15112e">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Leads">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" href="{{ asset('icons/icon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('icons/icon-192.png') }}">
    <script src="{{ asset('pwa.js') }}" defer></script>
    <title>@yield('title', 'Dashboard') · Lead Generator</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}?v={{ filemtime(public_path('app.css')) }}">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to content</a>
<header class="topbar">
    <div class="topbar-inner"><a class="brand" href="{{ route('dashboard') }}"><span class="logo" aria-hidden="true">↗</span><span>Lead<span class="brand-light">Generator</span><small>YOUR GROWTH WORKSPACE</small></span></a>
        <div class="topbar-actions"><span class="workspace-label"><span class="workspace-dot" aria-hidden="true"></span> Local workspace</span>@if(session('operator'))<form method="post" action="{{ route('logout') }}">@csrf<button class="secondary small">Sign out <span aria-hidden="true">↗</span></button></form>@endif</div>
    </div>
    @if(request()->attributes->has('operator_role'))
    @php
        $isAdmin = in_array(request()->attributes->get('operator_role'), ['owner', 'admin'], true);
        $moreLinks = ['reports' => 'Reports', 'channels.index' => 'Other messaging channels'];
        if ($isAdmin) {
            $moreLinks += [
                'connectors.index' => 'Lead sources',
                'automation.edit' => 'Skills, AI & email',
                'connections.edit' => 'Account connections',
                'team.index' => 'Team access',
            ];
        }
    @endphp
    <nav class="workspace-nav" aria-label="Workspace">
        @foreach(['dashboard' => 'My leads', 'discovery.index' => 'Find leads', 'outreach.index' => 'Email drafts', 'inbox.index' => 'Replies'] as $navRoute => $navLabel)
            @php($active = request()->routeIs($navRoute) || ($navRoute === 'dashboard' && request()->routeIs('leads.show')))
            <a href="{{ route($navRoute) }}" @class(['nav-link', 'active' => $active]) @if($active) aria-current="{{ request()->routeIs($navRoute) ? 'page' : 'location' }}" @endif>{{ $navLabel }}</a>
        @endforeach
        <details class="nav-more">
            <summary @class(['nav-link', 'active' => request()->routeIs(...array_keys($moreLinks))])>More <span aria-hidden="true">▾</span></summary>
            <div class="nav-menu">
                @foreach($moreLinks as $navRoute => $navLabel)
                    <a href="{{ route($navRoute) }}" @class(['nav-link', 'active' => request()->routeIs($navRoute)]) @if(request()->routeIs($navRoute)) aria-current="page" @endif>{{ $navLabel }}</a>
                @endforeach
            </div>
        </details>
    </nav>
    @endif
</header>
<main id="main-content">
<div class="app-tools"><button id="install-app" class="secondary small" type="button" hidden>Install app</button><p id="ios-install-help" class="muted small" hidden>On iPhone/iPad: open in Safari, then Share → Add to Home Screen.</p></div>
<div id="app-status" class="notice" role="status" aria-live="polite" hidden></div>
@if(session('notice'))<div class="notice" role="status">{{ session('notice') }}</div>@endif
@if($errors->any())<div class="error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
</main>
<footer><span class="footer-brand">↗ Lead Generator</span><span>Permission-based sourcing · Every outgoing email requires approval</span><span class="footer-private">Private workspace</span></footer>
</body></html>

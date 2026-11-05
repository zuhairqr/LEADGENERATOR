<svg class="{{ $class ?? 'workspace-icon' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('search')
            <circle cx="10.5" cy="10.5" r="5.5"/><path d="m15 15 4 4"/>
            @break
        @case('leads')
            <rect x="4" y="5" width="16" height="14" rx="2"/><path d="M4 10h16m-11 4h6"/>
            @break
        @case('email')
            <rect x="3.5" y="6" width="17" height="12" rx="2"/><path d="m4.5 8 7.5 5 7.5-5"/>
            @break
        @case('replies')
            <path d="M7 16H5l-2 3V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-6"/><path d="m9 10-2 2 2 2m-2-2h7"/>
            @break
    @endswitch
</svg>

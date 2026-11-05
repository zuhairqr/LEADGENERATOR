<div class="gauge {{ $tone ?? 'cyan' }}">
    <svg viewBox="0 0 200 116" aria-hidden="true">
        <path class="gauge-track" d="M 20 100 A 80 80 0 0 1 180 100" pathLength="100"/>
        @if($value !== null && $value > 0)<path class="gauge-fill" d="M 20 100 A 80 80 0 0 1 180 100" pathLength="100" stroke-dasharray="{{ min(100,max(0,$value)) }} 100"/>@endif
        @if($value !== null)<g transform="rotate({{ -90+1.8*min(100,max(0,$value)) }} 100 100)"><line class="gauge-needle" x1="100" y1="100" x2="100" y2="25"/></g><circle class="gauge-center" cx="100" cy="100" r="7"/>@endif
    </svg>
    <div class="gauge-scale"><span>0%</span><span>100%</span></div>
    <strong class="gauge-value">{{ $value === null ? '—' : number_format($value,1).'%' }}</strong>
    <span class="gauge-label">{{ $label }}</span>
    <span class="gauge-hint">{{ $hint }}</span>
</div>

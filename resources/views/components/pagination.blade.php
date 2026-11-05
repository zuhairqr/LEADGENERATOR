@if($paginator->hasPages())
    <nav class="app-pagination" aria-label="Pagination">
        <span class="muted small">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
        <div class="task-actions">
            @if($paginator->previousPageUrl())<a href="{{ $paginator->previousPageUrl() }}" rel="prev">← Previous</a>@endif
            @if($paginator->nextPageUrl())<a href="{{ $paginator->nextPageUrl() }}" rel="next">Next →</a>@endif
        </div>
    </nav>
@endif

@extends('layout')
@section('title', 'Reports')
@section('content')
<div class="heading page-heading">
    <div><div class="eyebrow">WORKSPACE INSIGHTS</div><h1>Reports</h1><p class="muted">See lead outcomes, replies, source activity and upcoming tasks.</p></div>
    <a class="button-link heading-action" href="{{ route('reports.export') }}">Export leads CSV</a>
</div>
<div class="stats">
    @foreach(['Lead records' => number_format($total), 'Projects won' => number_format($won), 'Leads with replies' => number_format($replied), 'Win rate' => $total ? round(100 * $won / $total, 1).'%' : '—'] as $label => $value)
        <section class="stat"><span class="stat-label">{{ $label }}</span><strong>{{ $value }}</strong></section>
    @endforeach
</div>
<div class="columns">
    <section class="panel">
        <h2>Pipeline activity</h2>
        @foreach(['Lead status' => $leadStates, 'Email status' => $messageStates, 'Reply labels' => $replyIntents] as $heading => $counts)
            <h3>{{ $heading }}</h3>
            <dl class="report-counts">
                @forelse($counts as $label => $count)
                    <div><dt>{{ ucfirst(str_replace('_', ' ', $label)) }}</dt><dd>{{ number_format($count) }}</dd></div>
                @empty<div><dt>No records yet</dt><dd>—</dd></div>@endforelse
            </dl>
        @endforeach
    </section>
    <section class="panel">
        <h2>Leads by source</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">Source</th><th scope="col">Leads</th><th scope="col">Won</th></tr></thead>
                <tbody>
                    @forelse($sourceStats as $row)
                        <tr><td>{{ config('leadgen.sources')[$row->source]['label'] ?? ucfirst(str_replace('-', ' ', $row->source)) }}</td><td>{{ $row->total }}</td><td>{{ $row->won }}</td></tr>
                    @empty<tr><td colspan="3">Source activity will appear once you have leads.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
        <h3>Recent collection failures</h3>
        @forelse($collectionFailures as $run)
            <p class="muted small">{{ $run->source }} · {{ $run->error_code }} · {{ $run->created_at->timezone('Asia/Karachi')->format('d M H:i') }} PKT</p>
        @empty<p class="muted">No recorded failures.</p>@endforelse
    </section>
</div>
<section class="panel">
    <h2>Upcoming and overdue tasks</h2>
    @forelse($dueTasks as $task)
        <article class="activity-row">
            <a href="{{ route('leads.show', $task->lead_id) }}#lead-notes">{{ $task->title }}</a>
            <span class="muted small"> · {{ $task->due_at->timezone('Asia/Karachi')->format('d M Y H:i') }} PKT</span>
            @if($task->due_at->isPast())<span class="status-badge" data-status="failed">Overdue</span>@endif
        </article>
    @empty<p class="muted">No pending tasks.</p>@endforelse
</section>
@endsection

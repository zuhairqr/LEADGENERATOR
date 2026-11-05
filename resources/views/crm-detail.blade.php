<section class="panel" id="lead-notes">
    <div class="section-heading">
        <div><h2>Notes and tasks</h2><p class="muted">Keep a record of your review and plan the next step.</p></div>
        @if($lead->reply_intent)<span class="tag">{{ ucfirst(str_replace('_', ' ', $lead->reply_intent)) }}</span>@endif
    </div>
    @if(!$isBusiness)
        <details class="filter-details" @if(!$countryReady && $canEdit) open @endif>
            <summary>Country review · {{ $country ?? 'Unconfirmed' }}</summary>
            @if($canEdit)
                <form method="post" action="{{ route('business.country', $lead) }}" class="stack">@csrf
                    <label>Country code<input name="country" maxlength="80" value="{{ $country }}" placeholder="e.g. US, GB, CA or AE" required></label>
                    <label>Link or evidence for the location<textarea name="country_evidence" maxlength="2000" required></textarea></label>
                    <label class="check"><input type="checkbox" name="country_verified" value="1" required><span>I verified the prospect's country. India and Pakistan are excluded.</span></label>
                    <div><button class="secondary">Save country review</button></div>
                </form>
            @else
                <p class="muted">{{ $countryReady ? 'Country policy permits outreach for this lead.' : 'Outreach is on hold under the country policy.' }}</p>
            @endif
        </details>
    @endif
    <div class="columns crm-columns">
        <div>
            <h3>Internal notes</h3>
            @if($canEdit)
                <form method="post" action="{{ route('crm.note', $lead) }}" class="stack">@csrf
                    <label>New note<textarea name="body" rows="3" maxlength="8000" required></textarea></label>
                    <div><button class="secondary">Save note</button></div>
                </form>
            @endif
            @forelse($lead->notes()->latest()->get() as $note)
                <article class="activity-row">
                    <p class="prewrap">{{ $note->body }}</p>
                    <p class="muted small">{{ $note->author }} · {{ $note->created_at->timezone('Asia/Karachi')->format('d M Y H:i') }} PKT</p>
                </article>
            @empty<p class="muted">No notes yet.</p>@endforelse
        </div>
        <div>
            <h3>Tasks</h3>
            @if($canEdit)
                <form method="post" action="{{ route('crm.task', $lead) }}" class="stack">@csrf
                    <label>Task title<input name="title" maxlength="200" placeholder="e.g. Review website findings" required></label>
                    <label>Due date and time (PKT)<input type="datetime-local" name="due_at" required></label>
                    <div><button class="secondary">Schedule task</button></div>
                </form>
            @endif
            @forelse($lead->tasks()->orderBy('due_at')->get() as $task)
                <article class="activity-row">
                    <p><strong>{{ $task->title }}</strong></p>
                    <div class="task-actions">
                        <span class="muted small">{{ $task->due_at->timezone('Asia/Karachi')->format('d M Y H:i') }} PKT</span>
                        <span class="tag">{{ $task->completed_at ? 'Done' : ($task->due_at->isPast() ? 'Overdue' : 'Pending') }}</span>
                        @if($canEdit && !$task->completed_at)
                            <form method="post" action="{{ route('crm.complete', $task) }}">@csrf<button class="secondary small">Mark complete</button></form>
                        @endif
                    </div>
                </article>
            @empty<p class="muted">No tasks yet.</p>@endforelse
        </div>
    </div>
</section>

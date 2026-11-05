@if($lead->appliesOnSource())
    <section class="panel" id="lead-contact">
        <h2>Apply through the original listing</h2>
        @if($lead->listing_expires_at?->isPast())
            <p class="muted">This listing is past its inbox cutoff. Check the original notice for updates.</p>
        @else
            @if($lead->source === 'public-web')
                <p class="muted">Use the submission method in the original notice. The <a href="#lead-overview">project brief</a> includes the method and deadline.</p>
            @else
                <p class="muted">Review the role and submit your application on {{ config('leadgen.sources.'.$lead->source.'.label') }}.</p>
            @endif
            <a class="button-link" href="{{ $lead->url }}" target="_blank" rel="noopener noreferrer">Open application listing ↗</a>
        @endif
    </section>
@else
<section class="panel" id="lead-contact" aria-labelledby="lead-contact-title">
    <div class="section-heading">
        <div>
            <div class="eyebrow">OUTREACH</div>
            <h2 id="lead-contact-title">Contact this lead</h2>
            <p class="muted">Find a public contact or use the original listing. Verify the email and permission before approving outreach.</p>
        </div>
        <span class="status-badge" data-status="{{ $lead->contact_email && $lead->contact_permission_at ? 'approved' : 'draft' }}">
            {{ $lead->contact_email && $lead->contact_permission_at ? 'Email verified' : ($lead->contact_email ? 'Email needs verification' : 'No verified email') }}
        </span>
    </div>
    @if(!$countryReady)
        <p class="muted small">Email outreach is on hold under the country policy. <a href="{{ $isBusiness ? '#lead-business' : '#lead-notes' }}">See country review</a>.</p>
    @endif
    <div class="columns contact-columns">
        <div>
            @if($isBusiness)
                <h3>Public contact details</h3>
                @forelse($lead->business?->contacts ?? [] as $contact)
                    <div class="public-contact">
                        <div>
                            <strong>{{ $contact['email'] }}</strong>
                            <div class="small muted">Public email · needs verification
                                @if(preg_match('~^https?://~i', $contact['source'] ?? ''))
                                    · <a href="{{ $contact['source'] }}" target="_blank" rel="noopener noreferrer">View source ↗</a>
                                @endif
                            </div>
                        </div>
                        @if($canEdit)<button type="button" class="secondary small" data-contact-address="{{ $contact['email'] }}" hidden>Use email</button>@endif
                    </div>
                @empty
                    <p class="muted">No public email has been recorded. Look for the contact page or form on the business website.</p>
                @endforelse
                @if($lead->business?->phone)<p>Public phone · <strong>{{ $lead->business->phone }}</strong></p>@endif
                <div class="task-actions">
                    @if($lead->business?->website)<a href="{{ $lead->business->website }}" target="_blank" rel="noopener noreferrer">Open website ↗</a>@endif
                    @foreach($contactPages as $page)
                        @if(preg_match('~^https?://~i', $page))
                            <a href="{{ $page }}" target="_blank" rel="noopener noreferrer" class="contact-page" title="{{ $page }}">{{ parse_url($page, PHP_URL_PATH) ?: 'Website page' }} ↗</a>
                        @endif
                    @endforeach
                    @if($canEdit && $lead->business?->website)<a href="#lead-audit">Find public contact details</a>@endif
                    @if(!$lead->business?->website)<a href="{{ $lead->url }}" target="_blank" rel="noopener noreferrer">Open original source ↗</a>@endif
                </div>
            @else
                <h3>Contact through the listing</h3>
                <p class="muted">Open the original listing to use its Apply or Message option. Save a direct email here only if the listing allows it.</p>
                <a class="button-link" href="{{ $lead->url }}" target="_blank" rel="noopener noreferrer nofollow">Open original listing ↗</a>
            @endif
        </div>
        <div>
            <h3>Save a contact email</h3>
            @if($canEdit)
                <form method="post" action="{{ route('outreach.contact', $lead) }}" class="stack">@csrf @method('PUT')
                    <label>Contact email<input id="lead-contact-email" type="email" name="contact_email" maxlength="254" value="{{ old('contact_email', $lead->contact_email) }}" placeholder="name@example.com"></label>
                    <label class="check"><input id="lead-contact-permission" type="checkbox" name="contact_permission" value="1"><span>I verified this exact address and have permission to email this lead. Leave unchecked to save the address for review.</span></label>
                    <div><button>Save contact</button></div>
                </form>
            @else
                <p>{{ $lead->contact_email ?: 'No contact email saved.' }}</p>
            @endif
            <p class="muted small">Prepare a proposal and review the draft before sending. Each outgoing email requires approval.</p>
            <div class="task-actions">
                <a href="#lead-proposal">Go to proposal</a>
                <a href="{{ route('outreach.index', ['lead' => $lead->id]) }}">Review emails for this lead →</a>
            </div>
        </div>
    </div>
    <details class="filter-details">
        <summary>Email activity and outcomes</summary>
        @if($canEdit)
            <form method="post" action="{{ route('outreach.outcome', $lead) }}" class="filters">@csrf
                <label>Record a verified outcome<select name="outcome"><option value="reply">Client replied</option><option value="bounce">Email bounced</option><option value="unsubscribe">Client opted out</option></select></label>
                <button class="secondary">Record outcome and stop follow-ups</button>
            </form>
        @endif
        <p class="muted small">Reply · {{ $lead->replied_at ?: 'Not recorded' }} / Bounce · {{ $lead->bounced_at ?: 'Not recorded' }} / Opt-out · {{ $lead->unsubscribed_at ?: 'Not recorded' }}</p>
        @forelse($events as $event)
            <p class="small muted">{{ $event->created_at }} UTC · {{ str_replace('_', ' ', $event->event) }}</p>
        @empty<p class="muted">No outreach activity yet.</p>@endforelse
    </details>
</section>
@if($canEdit)
    <script>
        (() => {
            const email = document.getElementById('lead-contact-email');
            const permission = document.getElementById('lead-contact-permission');
            email.addEventListener('input', () => { permission.checked = false; });
            document.querySelectorAll('[data-contact-address]').forEach(button => {
                button.hidden = false;
                button.addEventListener('click', () => {
                    email.value = button.dataset.contactAddress;
                    permission.checked = false;
                    email.focus();
                });
            });
        })();
    </script>
@endif
@endif

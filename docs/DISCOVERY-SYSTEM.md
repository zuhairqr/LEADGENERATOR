# Discovery system: implementation and connection status

Gmail connection evidence updated 5 November 2026; broader implementation recorded 3 November 2026. This describes the local implementation, not a hosted deployment or proof of inbox delivery.

## Targeting

All businesses, worldwide, with India and Pakistan excluded. There is no fixed niche restriction. All-business discovery rotates broad business search terms over time; it does not enumerate every business worldwide. Saved services cover websites/platforms/frameworks, SaaS, AI/agents, automation, CRM/ERP, ecommerce, mobile, APIs, SEO/marketing, UX, maintenance/security, databases, cloud/DevOps, payments/bookings and performance/modernization.

Public search candidates are labelled unverified. Country evidence comes from an explicitly supplied import country, website structured-data country, or operator confirmation. Country-unknown records cannot proceed to outreach. Search exclusions are an initial filter, not country proof. Changing a company domain clears its old country evidence and queued approvals. Contact data never grants permission to send.

## Application flow

| Step | Implemented behavior | Location |
|---|---|---|
| Discover | Six request-source branches plus public business search; approved JSON feeds; manual/CSV import | `/`, `/discovery`, `/connectors` |
| Normalize | Merge company domains/contact records; retain source URLs and attribution | Business lead detail |
| Qualify | Explainable service matching, country hold, AI service-fit review; business prospects distinguished from buyer requests | Lead detail, Skills & AI |
| Audit | Fetch real public HTML and record observed facts, hashes, status and limitations | Lead detail → website audit |
| Offer | Personalized proposal/email from confirmed business facts and completed audit evidence | Lead detail, `/outreach` |
| Approve | Exact recipient, content and sender hash; edits invalidate approval; admin permission required | `/outreach`, `/channels` |
| Send | Persist reservation before provider call; save receipt; ambiguous delivery requires manual resolution | Five-minute outbox worker |
| Reply | Match known recipient and message/thread references; classify rules with operator correction | `/inbox` |
| CRM | Notes, status, activity, next-action reminders, reports, export | Lead detail, `/reports` |
| Follow up | Due AI follow-up drafts, separate approval for each; stop on replies/opt-outs/bounces/closed leads | Outbox and channel worker |

`/team` provides admin, reviewer and viewer accounts. Viewer is read-only; reviewer cannot authorize sending or private integrations. Disabling an account or changing its password revokes its session. Existing owner login remains available.

## Website audit boundaries

The audit checks one public HTML page: title, meta description, viewport tag, H1 count, image-alt presence, document size, HTTPS, explicit mailto/contact links and declared structured-data country. It stores fetched/final URLs, status, timing, HTML SHA-256, timestamp and observations. The score is an HTML checklist score. It is not Lighthouse, a rendered mobile test, a vulnerability scan, a ranking measurement or proof of business impact. Non-HTML responses fail explicitly. Access-denied/challenge pages are limited and do not produce supposed website defects.

Only public HTTP/HTTPS destinations are allowed. DNS and each redirect are checked; cURL pins an allowed public IP. Private/reserved IPs, metadata hosts, credential-bearing URLs and non-web ports are refused. Authenticated connector requests never follow redirects. The audit does not execute website JavaScript or obey instructions embedded in page content.

## Source universe and real access

Every platform named in the image is included in the source catalog. The actual access path is shown below; adding a catalog entry is not a native account integration.

| Image category | Available now | Additional access needed |
|---|---|---|
| Freelancing platforms | Manual/CSV, permitted JSON API/feed mapping | Each platform's authorized API/export/gateway; no private scraping installed |
| Social media | Public indexed Facebook/Instagram/Reddit/LinkedIn/HN request discovery | Meta Page/Instagram authorization and public HTTPS callbacks for private messages/comments; other network-specific access |
| Jobs and project posts | Remotive, Remote OK and Arbeitnow adapters; manual/CSV | Authorized access or exports for additional named boards |
| Business directories | Public business search, company website audit, manual/CSV, approved JSON API mapping | Official directory/provider API key and appropriate endpoint/mapping |
| Startup/business communities | Indexed HN request discovery, manual/CSV/approved feed | Source-specific feeds or authorized exports |
| Classifieds/marketplaces | Manual/CSV/approved business JSON feed | Source permission and suitable API/export |
| Q&A/discussion | Indexed Reddit/HN buyer requests with intent filtering | Other community feeds/API permissions |
| B2B providers | Encrypted-token business JSON connector, import mapping | Owner's provider account/plan/key and approved data use |
| Agency marketplaces | Manual/CSV/approved feed | Provider API/export access |
| Events/networking | Manual/CSV/approved feed | Event/provider authorization; attendance is not outreach permission |
| Ecommerce/store discovery | Public business search, static store website audit, manual/CSV/JSON feed | Provider-specific discovery API/export access |
| Shared lead inbox | Persistent merged company records, provenance and contacts | Import rights and contact/country review remain necessary |

Generic JSON feeds currently ingest **business records**, not arbitrary executable scripts or platform sessions. Complex pagination, OAuth providers and platform-specific rate rules may require a dedicated adapter. A run reads at most one page/100 rows. This is explicitly shown in the connector form rather than pretending every platform works with one API shape.

## Configurable connection contracts

Configure approved public HTTPS endpoints in `/connectors`. Secrets use an encrypted private field; blank preserves the current token. Header choices are Bearer Authorization, X-API-Key and X-Goog-Api-Key. Endpoints/request bodies must not contain credentials. Reconfirm endpoint ownership, data-sharing permission, provider terms and costs when saving.

- **Business feed:** GET or POST JSON. Set the items-array path and field mapping for name, website, source_url, source_id, niche, location, country, email, phone and description. Dotted paths only. Per connector: maximum four attempts per rolling 24 hours including failures, bounded response, recorded run counts and failure backoff.
- **CRM webhook:** a confirmed manual sync sends `event=lead.updated`, lead ID/kind/title/status/source, business summary, contact_email, reply_intent, next_action_at and updated_at. The endpoint must return 2xx. An Idempotency-Key accompanies the request. An ambiguous acknowledgement is recorded without automatic resending.
- **Outreach gateway:** a configured permitted provider gateway receives event=approved.outreach, message_id, channel, recipient, body and parent_id, with an Idempotency-Key header. It must acknowledge acceptance. This is a generic transport contract, not native WhatsApp/LinkedIn/Meta authorization. Exact-message approval and contact permission remain required; every due follow-up is a new draft.
- **Channel reply callback:** `POST /api/sales/channel-receipt` uses the private import bearer credential. Body: message_id (UUID), sender matching the saved destination, outcome (reply/unsubscribe/bounce), optional provider_event_id and snippet. Match/dedup before updating CRM and stopping queued outreach. External providers require a reachable HTTPS gateway; the current app is local-only.

CRM and provider receipt acknowledgement do not prove actual delivery, an opened message, a sale, or revenue.

## Gmail reply connection

The saved `Gmail account 2` credential is OAuth-connected. On 5 November 2026 the actual private account-verification webhook returned HTTP 200 with `ok=true`, `read_access=true` and `sender_matches=true`. The earlier failure was `GMAIL_API_DISABLED`; the owner enabled Gmail API in the OAuth client's project. The authenticated app's **Finish Gmail OAuth connection — no email** action selected Gmail and verified its sender. **Check tracked replies now** completed with zero matches. The outbox is empty, so this does not demonstrate receipt or matching of a real client reply, nor email sending/delivery. Saved SMTP authentication was rejected previously; this check does not validate SMTP.

The existing WSL-private listeners on `172.23.16.1:8083` (app callbacks) and `:8084` (approved-message envelope checks) were subsequently started with owner approval. Seven probes from the n8n container passed: both health endpoints returned 200, private dashboard routes returned 404, unauthenticated protected POSTs returned 401, and the envelope listener rejected other app routes. The published five-minute inbox workflow's production-success event at `2026-11-04 20:56:33 UTC` matched Laravel's automatic `mailbox_sync.checked_at`; sync succeeded with zero tracked contacts/replies. These local processes must remain running for automatic tracking. No email was sent or approved during setup.

1. In n8n's private Credentials form, complete the owner's Google OAuth sign-in and consent for the intended sending account. If the Google OAuth client is missing, configure that owner-controlled client first. Do not send secrets/OTP in chat.
2. Sign into Lead Generator and open `/inbox`. Use **Finish Gmail connection**. The app verifies the actual Google account address against the configured sender and probes message-read permission before selecting Gmail as sender. Existing approvals are invalidated.
3. The five-minute sync queries only saved outgoing-client addresses and known conversations, with batches/page cursors and a checkpoint overlap. It requests metadata headers and short snippets, not attachments/full MIME bodies. Unmatched records are discarded before Laravel persistence. Failed authorization/scope checks hold the connection.
4. A real reply can only be proven after a human-approved message is sent and a matching reply arrives. No client message has been approved or sent by this setup.

Reply intent is a reviewable keyword classification, not an AI judgment. Automatic replies also stop follow-ups for review. Unsubscribe/bounce suppression applies across records using the same contact. Comprehensive delivery-status/DSN parsing and open tracking are not implemented. SMTP-only users still require a separately configured inbox credential; the installed current path is Gmail OAuth.

## Discovery quality and own-inbox test (5 November update)

Business discovery now rotates concrete business sectors for “All businesses”. The form supports business discovery and explicit service-request discovery; scheduled searches periodically use a saved service in their request query. Platform domains and their subdomains, editorial/listing results, excluded-country domains/mentions, and software/marketing vendors advertising to the target industry are filtered. Vendor businesses remain eligible when that is the actual selected niche. Accepted results are ranked and deduplicated by domain; each run retains filter counts and each imported business retains its search evidence. The intake endpoint rechecks this policy before importing cached results.

Search relevance is a heuristic, not confirmed buying intent, location or contact permission. Static HTML findings now contribute to fit only where they match saved services; one missing metadata tag no longer makes a high-priority opportunity. Nine untouched automated platform records were marked rejected with private before-state backups, not deleted. No country/source/contact approvals were granted.

The latest live preview fetched 30 results, filtered 23 and retained six plus one duplicate. Reviewing those six exposed additional vendor/editorial false positives, so the final title filters and singular-sector/contact query were tightened afterward. Those final refinements pass offline checks; their next real search is pending the existing four-attempt rolling daily limit. The budget was not reset and these previews did not import leads.

`/settings/mail-test` is an owner/admin private form for a fixed, non-promotional email to a second inbox the operator controls. Preparing it creates a draft only. It expires in two hours, is bound to the verified sender and recipient, permits one fixed follow-up draft after two minutes, and allows at most two test preparations per rolling day. Both emails require their own exact-message approval. The normal Gmail transport, reservation, receipt, mailbox matching and cancellation paths are used; no AI call is needed. Technical records are excluded from prospect and sales reports and do not acquire country approval. A changed sender, recipient or body prevents sending.

On 6 November 2026, the fixed initial email arrived in a separate authorized Gmail inbox. A reply in the same conversation matched the original message and technical lead. Reply sync cancelled the pending follow-up draft before sending. Gmail replaced the Message-ID supplied in the outgoing message, so the local transport now reads the stored Message-ID from Gmail metadata after acceptance. The Gmail thread ID remains available for matching if that metadata lookup fails.

## Workers and limits

The existing six-source collector runs every six hours. Business discovery has a five-minute worker but searches at most once per six hours, with a four-attempt rolling daily cap; it audits at most one candidate per tick and once per lead/day. Approved feeds have their own attempt limits/backoff. AI remains bounded by the saved daily attempt budget and one draft per tick. Email/channel dispatch processes bounded queues. Every message and follow-up requires human approval. Collection does not imply contact permission.

Seven workflows are published: leadgenSalesAi01, leadgenSalesWorker01, leadgenBusinessWorker01, leadgenGmailConnect01, leadgenGmailRead01, leadgenSalesGmail01 and leadgenSalesInbox01. Gmail installed/readiness flags remain separate. The old six-branch collector remains published. Only this project's n8n container was restarted; unrelated containers and existing accounts/data were preserved.

Local execution requires laptop, Docker, dedicated MariaDB, Laravel and private listeners. The import worker uses the private WSL address on 8083; Gmail retrieves canonical approved content from a separate, restricted listener on 8084. Separate PHP processes avoid a blocking self-callback on the Windows development server. Both require the same authenticated API policy, and neither exposes private dashboard pages. It is not a cloud or mobile background service.

## Executed evidence

- 85 expanded integration checks: isolated test DB, rolled-back fixtures and faked network/mail.
- 40 sales, 51 Laravel/MariaDB, 21 smoke, 26 JavaScript/PWA and 7 password recovery checks passed.
- Actual public SearXNG business search imported three unverified prospects, all held for country evidence. Their persisted audits currently show two limited responses and one failure; none is represented as a completed audit or qualified client. The current sources have partial coverage.
- Live public HTML fetch/audit probe returned HTTP 200 with recorded evidence/hash. A protected business website was correctly recorded as limited rather than given invented defects.
- One real bounded OpenAI request through the actual business ProposalWriter returned valid, personalized proposal/email output using a fictional business/audit only. Test DB records rolled back, emails sent zero. Private safe evidence: storage/n8n/logs/business-ai-probe.json.
- Prior 3 November infrastructure evidence: n8n healthy and container-to-private Laravel `/up` HTTP 200. That historical listener check does not establish current listener availability.
- 5 November: the Gmail verification workflow was narrowly updated/published after a verified private n8n backup; only the project n8n container was restarted. Its live profile/read probe passed with the configured sender matching. The actual authenticated `/inbox` browser page showed **Verified account matches sender** and completed sync with zero replies. Both private callback listeners passed seven n8n-side probes, and a real five-minute scheduled inbox success matched the persisted Laravel sync timestamp. No email was sent; real reply matching and recipient delivery remain unproven because the outbox/tracked-contact set is empty.
- Four focused mail workflow checks passed, including fixed diagnostic codes that never return raw provider errors, credentials or request data.

Run regressions:

```powershell
php tests/expanded-integration.php
php tests/sales-integration.php
php tests/mail-connection-test.php
php tests/integration.php
php tests/smoke.php
php tests/password-recovery.php
& storage/n8n/node/node-v24.22.1-win-x64/node.exe --test tests/expanded-workflows.test.cjs tests/collector.test.cjs tests/mail-workflow.test.cjs tests/pwa.test.mjs
php artisan view:cache
```

Live provider probes require explicit saved-connection reuse approval and incur a bounded provider request. `php tests/live-business-ai-probe.php --ai` uses fictional test records and rolls them back; it sends no email. Backups of the project database and n8n state were taken before deployment. Preserve APP_KEY, the n8n encryption config and private backups.

# Skills, proposals, approved email and follow-ups

## Gmail verification — 5 November 2026

`Gmail account 2` is OAuth-connected. After the owner enabled Gmail API in the OAuth client's project, a real profile/read probe passed and the address matched the configured sender. Lead Generator selected Gmail through its authenticated connection action. Manual reply sync completed with zero matches and an empty outbox. The existing private listeners on 8083/8084 were then started with owner approval; all seven n8n-side health/access probes passed. The published five-minute inbox workflow recorded a production success matching Laravel's automatic sync timestamp. No email send, recipient delivery or real client-reply matching has been proven: there are currently no tracked outgoing conversations. The laptop and existing local processes must stay running. See the current [connection evidence](../docs/DISCOVERY-SYSTEM.md#gmail-reply-connection). Older dated sections below are historical.

## Expanded scope — 3 November 2026

See [the current discovery system implementation](../docs/DISCOVERY-SYSTEM.md) for business search, website evidence/audits, strict India/Pakistan exclusion and country review, CRM/team/reporting, configurable feed/CRM/outreach gateways and Gmail metadata-reply workflows. Gmail was pending owner authorization at that 3 November snapshot; the 5 November evidence above supersedes that connection status. A real synthetic business-audit AI proposal test passed with zero emails sent. Earlier dated sections below describe the prior request-only pipeline.

## Current local state (31 October 2026)

The six-source collector is published every six hours. Its next permitted run uses your saved skills, exclusions and location keywords. The approved-outbox worker is published every five minutes and its real n8n-to-Laravel HTTP execution passed with zero messages sent.

The AI drafting workflow is published using the owner-saved **OpenAI account** project credential. A real request through Laravel's actual `ProposalWriter`, the authenticated n8n webhook and that credential generated a validated personalized proposal/email for a fictional Laravel/Stripe checkout request. The model correctly marked the fixture as not a qualified real lead. The synthetic profile existed only inside the test process; no real profile, lead or outbox message was saved and no email was sent. Safe probe metadata and the explicitly labelled fictional output are under ignored `storage/n8n/logs/sales-ai-probe.json` and `sales-ai-synthetic-draft.json`.

The older `n8n Assistant model` connection retains its **instance / Assistant-only usage scope** and was not converted or copied. The owner has now confirmed their business profile, enabled automatic drafts and selected the tested `OpenAI account` connection explicitly. Gmail SMTP details are saved; a TLS/authentication-only probe reached an authentication rejection. Sending remains blocked by the unverified sender. A valid Google App Password or Gmail OAuth connection is needed. Provider email delivery and automatic inbox sync have not been tested.

## First use

1. Open `http://127.0.0.1:8079/setup/operator` and privately create your own operator account if it is still empty. Sign in.
2. Open **Skills & AI** (`/settings/automation`). Set skills/keywords, permitted sources, exclusions and locations. Defaults are editable targeting suggestions, not verified claims about your business. The source approval gates remain independent.
3. Enter your real business name, verified services/experience/portfolio and signature. Confirm these facts and permission to share listing text with the model. Choose a supported model on your actual provider connection. Automatic drafting stays idle until facts, consent and the workflow AI connection are ready.
4. In n8n **Credentials**, add an OpenAI workflow credential using its private form. An Assistant provider connection is separate. If several workflow connections exist, save the exact chosen credential name in Skills & AI.
5. Choose **SMTP** or **Gmail OAuth** in the email form. SMTP passwords are encrypted with Laravel's APP_KEY; keep that key and private database backups safe. Port 587 requires STARTTLS; port 465 requires TLS from connection start. Blank password preserves the stored password. Gmail requires a connected `gmailOAuth2` n8n credential and the exact sending account address. For automatic SMTP inbox replies, add a separate `imap` credential in n8n. Save the exact Gmail/IMAP credential names in the app.

   For Gmail SMTP, enter a Google App Password in the private password field. App Passwords require 2-Step Verification; follow [Google's current instructions](https://support.google.com/mail/answer/185833?hl=en). After saving, use **Check saved SMTP — no email sent**. This verifies TLS, authentication and whether the configured sender matches the authenticated username; it does not transmit an email, establish inbox delivery or enable the ownership checkbox. Errors are fixed safe codes, never authentication transcripts. Saving settings invalidates the previous check, and edits during a check discard its stale result.
6. From the project directory run:

   ```powershell
   powershell -NoProfile -File deployment/n8n/setup-sales.ps1
   ```

   This backs up project n8n state, selects existing workflow credentials by type/name, imports and publishes configured workflows, leaves missing-credential workflows as drafts, and restarts only `leadgenerator-sandbox-n8n-1`. The instance-only Assistant connection is never converted or copied. It then writes non-secret publication status for the app. Adding credentials does not by itself prove provider availability; use an explicit bounded test before relying on production sending.
7. On a relevant lead, save a verified client email and confirm direct email is permitted for that opportunity. No contact address is invented or guessed from a profile/job listing.
8. Review the proposal and email in **Approvals & follow-ups** (`/outreach`). Edit first, then approve the exact subject, body, recipient and sender. The worker picks up approved messages. A follow-up is always a new draft requiring separate approval.

## Persistent behavior

- AI qualifies a buyer request and writes a proposal plus personalized plain-text email. Listing text is untrusted, no tools are connected, provider output is validated and rendered as escaped text. Human review is still needed for factual claims and source eligibility.
- At most one AI draft is attempted per five-minute tick, subject to a rolling 24-hour attempt budget (default five, including failures). Follow-ups due for review take priority. A repeated initial-draft request returns the existing record. Existing failed drafts require explicit retry.
- Source fetches retain the shared four-attempt rolling 24-hour limit, independent of the AI budget. Search snippets can be stale and have unknown publication dates; the date filter is enforced for dated feeds and the search engine is asked for the last month. Public social discovery does not access private posts or inboxes.
- Exact-message approvals are hashed. Editing content or changing a contact/sender invalidates approval. A lead status such as `approved` is only an internal label; it cannot authorize email dispatch.
- Dispatch persists `sending` before contacting the provider. Provider acknowledgement becomes `sent` with a message/thread identifier and follow-up due date. That is not proof of inbox delivery or opening. A lost acknowledgement/crash becomes `delivery_unknown`; it is never retried automatically. Check the provider's Sent folder and explicitly record sent/not-sent before any new approval.
- Replies, opt-outs, bounces and closed leads stop sending. Matching inbox receipts require the client's exact email plus a known message/thread reference. Inbox bodies are not forwarded to Laravel by the reply adapter. Gmail/IMAP listeners remain uninstalled until your own credential is configured. Bounces/opt-outs can be recorded manually; comprehensive DSN/unsubscribe parsing and read/open tracking are not implemented.
- Opt-out/bounce suppression applies to the same contact address across imported leads. Sent email cannot be recalled when an outcome arrives after dispatch began. No promise of transport-level exactly-once delivery is made; ambiguous sends need manual resolution.
- Sending and collection require the laptop, Docker Desktop, project MariaDB, Laravel and the private Docker-facing listener to be running. This is local setup, not cloud deployment or a mobile background service.

## Workflows and interfaces

| Workflow | ID | Condition |
|---|---|---|
| Multi-source collection | `leadgenMultiff89be28` | Published, six-hour schedule |
| Approved outbox / follow-up drafts | `leadgenSalesWorker01` | Published, five-minute schedule |
| Private AI drafting | `leadgenSalesAi01` | Published with the saved workflow AI credential; live writer test passed |
| Approved Gmail transport | `leadgenSalesGmail01` | Generated/published only after Gmail credential selection |
| Match own inbox replies | `leadgenSalesInbox01` | Generated/published only after Gmail/IMAP credential selection |

`POST /api/sales/tick` and `/api/sales/receipt` require the private import bearer credential. The private AI/Gmail n8n webhooks also use header authentication. Exported workflows contain credential IDs/names only, never secret values. Execution payload retention is disabled. Business drafts and the activity timeline persist in the project MySQL database.

## Verification

```powershell
php tests/sales-integration.php
php tests/integration.php
php tests/smoke.php
& storage/n8n/node/node-v24.22.1-win-x64/node.exe --test tests/collector.test.cjs tests/mail-workflow.test.cjs tests/pwa.test.mjs
php artisan view:cache
```

Sales integration tests use only `lead_generator_test` on the dedicated MariaDB port 3310, roll back fixtures, fake AI HTTP and replace the email transport. They do not send emails. The live drafting probe is separate and must only be run with provider approval:

```powershell
php tests/live-sales-probe.php --ai
```

This runs the real proposal writer with a process-local fictional profile and listing, sends no customer data/email and creates no lead/outbox records. It saves safe validation metadata plus an explicitly labelled synthetic draft. It does not configure or verify the operator's real business facts or email account.

An explicitly authorized SMTP authentication-only check is also available:

```powershell
php tests/live-smtp-probe.php --authenticate
```

It performs TLS/authentication and disconnects without sending `MAIL`, `RCPT` or `DATA`. It stores only safe result flags/error codes; it never prints credentials or raw SMTP exception text.

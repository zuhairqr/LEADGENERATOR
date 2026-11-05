# Lead Generator

## Expanded discovery system — 3 November 2026

The application now includes public business discovery, manual/CSV company intake, cross-source company deduplication, source provenance, static website evidence/audits, country review, AI business offers, exact-message approvals, a shared reply inbox, CRM notes/tasks, reports/CSV export, team roles, and configurable approved JSON feed/CRM/outreach gateways. Targeting is **all businesses worldwide**, excluding **India and Pakistan**, with the owner's 18 service groups saved. Unknown countries remain on hold before outreach. See [implemented scope and access requirements](docs/DISCOVERY-SYSTEM.md).

The business/audit worker and seven sales/AI/Gmail workflows are published locally. A real public search imported **three unverified business candidates**, all held for country evidence. Their current audits are two limited responses and one failure. A separate live public HTML audit probe passed; challenged sites never produce fabricated findings. One bounded real AI call through the saved workflow connection produced a validated business-audit proposal and email using fictional fixtures only; the test database records rolled back and no email was sent.

**Gmail test, 6 November 2026.** The fixed test email arrived in a separate authorized Gmail inbox. Its reply matched the exact technical lead and cancelled the unsent follow-up draft. The local Gmail transport now reads the provider's stored Message-ID after sending. See [Gmail connection status](docs/DISCOVERY-SYSTEM.md#gmail-reply-connection). Private Meta and additional platform feeds require their own authorized connections.

**Local verification, 6 November 2026.** 308 automated checks passed across discovery, CRM, sales, technical mail, Laravel, PWA, workflows and password recovery. Integration suites use `lead_generator_test` with faked external calls. Laravel, n8n and the search service returned HTTP 200 on local health checks. Browser QA used a separate database and fictional records to exercise intake, country review, website evidence, task ordering, email filters and reports.

## Sales pipeline (31 October 2026)

Skills-based multi-source search, AI qualification/proposal/email drafting, exact-message approval, a persistent outbox, follow-up drafts and reply/outcome tracking are implemented. The six-hour collector and five-minute approval worker are published; a real worker HTTP run passed. SMTP and Gmail OAuth options are available in `/settings/automation`; review messages at `/outreach`.

The saved **OpenAI account** workflow connection is now attached and the private AI workflow is published. A bounded live test through the actual Laravel proposal writer generated and validated a personalized proposal/email for a fictional listing. The AI correctly classified that fixture as not a real qualified lead. Its result is test-only; no live lead, business profile or outgoing email was created. The Assistant-only connection remains separate.

The owner has saved/confirmed business facts and enabled automatic drafts. Gmail SMTP settings are present, but the saved credentials were rejected during the authentication-only check; a valid Google App Password or Gmail OAuth connection is still required. No client email has been sent. `/settings/automation` now includes **Check saved SMTP — no email sent** with safe persisted status; changing mail settings clears the old check. Automatic inbox tracking still needs the owner's Gmail/IMAP connection. See [sales pipeline setup and exact verification limits](n8n/SALES-PIPELINE.md). Older status sections below describe earlier delivery stages.

## Multi-source collection (30 October 2026)

The local n8n collector now has six independent branches: Remotive, Remote OK, Arbeitnow, Facebook public search, Instagram public search, and Reddit/LinkedIn/HN public search. It uses an encrypted import credential, a private Docker-facing API listener, durable per-source attempt limits and run history, strict buyer-intent filtering, and idempotent batch imports. Public search coverage is incomplete. Facebook/Instagram private inbox/comments remain pending your Meta connection and public HTTPS webhook. The dashboard operator account must be configured by its owner. See [the current multi-source setup](n8n/MULTI-SOURCE.md); earlier initial-delivery status below is historical.

Laravel 12 / PHP 8.2+ starter for a single-operator lead inbox, MySQL persistence, n8n ingestion and human-reviewed OpenAI proposals.

## Delivery status

Local Laravel runtime installed and database migration executed on 28 October 2026. **Not deployed to hosting.** The login page is available at `http://127.0.0.1:8079/login`; the operator must privately configure their own account before signing in. No real source collection, live AI request or outreach has been executed. No live hosting folder is connected to this workspace.

### Local Windows setup and verification (28 October 2026)

- Composer dependencies and `composer.lock` are installed. `composer audit --locked` returned no security vulnerability advisories at the time of the check.
- Private local `.env` and `APP_KEY` are created. HTTP local sessions use `SESSION_SECURE_COOKIE=false`; production must use HTTPS and secure cookies.
- A dedicated, loopback-only MariaDB instance uses port **3310** and `storage/local-mysql/`, independently of the shared XAMPP data directory. `lead_generator_local` is the local inbox; `lead_generator_test` is reserved for disposable integration fixtures. Private database credentials are generated into ignored local files and never displayed.
- `php tests/integration.php`: **26 Laravel/MariaDB checks passed**, covering health, Blade login/dashboard rendering, authentication with ephemeral test-only credentials, logout, CSRF enforcement, private cache headers, import authentication and validation, source/host restrictions, deduplication, protected fields, search, escaped markup and persisted lead edits. Test records are rolled back. No AI/provider requests are sent.
- `php tests/smoke.php`: **21 checks passed**. `node --test tests/pwa.test.mjs`: **11 tests passed**. Blade compilation and migration status checks passed.
- Served HTTP checks: login, `/up`, stylesheet, manifest and service worker returned 200; private `.env`, Composer manifest and the administrative database file were rejected. The actual login page was inspected in the host browser. Authenticated browser/device flows and live provider integrations remain unverified.
- Port 8000 belongs to another application on this laptop; this project uses **8079**.

From this project directory, restart local services after reopening the laptop:

```powershell
powershell -NoProfile -File .\deployment\start-local.ps1
```

Privately create the operator account in your own terminal:

```powershell
php artisan leadgen:setup-operator
```

For a forgotten password on this local computer, run `powershell -NoProfile -File deployment/reset-local-password.ps1` or the interactive `php artisan leadgen:reset-password`. Enter your own new password twice (hidden input, minimum 12 characters). Recovery changes only the existing password hash, preserves the account email and all integration/data settings, and invalidates old operator sessions. It does not create a public password-reset endpoint or change n8n accounts.

Enter your email and hidden password when prompted. Passwords are hashed; do not send them in chat. Account setup requires interactive input and does not accept credentials as command arguments. If environment configuration was cached or a server was started with `--no-reload`, restart that server after setup.

`deployment/initialize-local.php` is the one-time initializer for a freshly initialized project-owned MariaDB data directory on port 3310. It refuses to overwrite existing environment/admin files and verifies the server data directory before creating databases. Do not run it against an existing/shared server.

The local n8n **2.45.4** server is installed and running at `http://localhost:5679/`. Its readiness endpoint and editor returned HTTP 200, and the owner-account setup page was verified in the host browser. It uses a separate portable Node v24.22.1 runtime; system Node v20.20.2 remains unchanged. See `deployment/n8n/README.md` for restart instructions, persistent data and current limitations.

Remaining setup: your Laravel operator account and n8n owner account; your decision about reusing or provisioning an OpenAI key; verified business portfolio; the Laravel import credential and n8n connection; source permission review and manual connector validation. The generated `n8n/remotive-contract-leads.json` remains inactive, source-gated and pointed at a placeholder destination. PWA device installation and native APK work remain pending.

### Implemented source

- Session-based operator login, password-hash verification, CSRF protection and login throttling.
- Responsive dashboard: counts, title search, status and keyword-score filters, pagination, project review and proposal editor.
- MySQL migration with source-ID and normalized-URL unique constraints.
- Authenticated, rate-limited `POST /api/leads/import`; source permission and listing-host allowlists; validation; idempotent duplicate response.
- Explainable keyword matching for Laravel, websites, AI automation, AI integration and merchant/payment integrations. “Merchant” is an assumption from the request and should be confirmed.
- Manual OpenAI fit assessment and draft generation, with consent checkbox, verified business facts, validated JSON output, no automatic sending, and protection against overwriting an existing draft.
- Inactive n8n manual-import template; deliberately no approved sources, real scraper or scheduled collection.
- Mobile PWA source: standalone manifest, generated 192/512 PNG icons, browser-supported install prompt, iOS installation help, offline warning and public-only offline fallback. No private lead/proposal/login caching, queued writes or background AI requests.
- Private/no-store response middleware for Laravel routes and authentication revalidation on restored browser pages.

### Not implemented yet

Source-specific connectors, scheduled crawling, provider pagination/backoff, n8n error alerts, a processing-history dashboard, account roles/teams, queued AI jobs, automated data retention/deletion, exports and automated outreach. AI fit assessment currently runs when the operator requests a draft, not during import. File-based sessions/cache support a single-server starter, not a horizontally scaled deployment.

## Original source-only delivery evidence (before the local setup above)

Re-executed `php lead-generator/tests/smoke.php` successfully on PHP 8.3.36 (exit 0): **21 checks passed**, including parsing 22 non-Blade PHP files, keyword groups, false-positive boundaries, URL deduplication, unsafe URL rejection, JSON manifests and n8n graph references.

Executed `node --test lead-generator/tests/pwa.test.mjs` (exit 0): **11 tests passed**. Tests evaluate the actual worker/client scripts in mocked browser contexts: public-only precache, scoped cache cleanup, network-only private navigation, nested-route offline fallback, mutation/API non-interception, icon dimensions, secure-context registration, explicit installation gesture and restored-page revalidation. These are not real-device/browser tests or Laravel middleware integration tests.

Executed `python lead-generator/deployment/generate-icons.py` (exit 0), generating both PNG icons using only the Python standard library.

The original delivery runtime probe found `pdo_mysql`, `mbstring`, `openssl`, `curl`, `xml`, `ctype`, `fileinfo`, `tokenizer`. At that delivery stage Composer dependencies, lockfile, Laravel boot, Blade compilation, authentication, migration and browser testing had not been completed. The newer Windows local verification above supersedes those local-runtime gaps; n8n/OpenAI live integrations remain unverified.

## Installation on cPanel (operator steps, NOT already executed)

1. Choose an HTTPS domain/subdomain, for example `leads.YOUR-DOMAIN`. Confirm PHP 8.2+ and Laravel-required extensions. Create a dedicated MySQL database/user with privileges only for that database.
2. Upload this folder **outside the public web root**. Set that domain's document root to the project's **`public/` directory only**. Do not expose the whole application in `public_html`; `.env`, storage, vendor and configuration must not be web-accessible. If cPanel cannot set the correct document root, resolve that with hosting support before launch.
3. With Composer available, install and audit dependencies from the project directory:
   ```sh
   composer install --no-dev --optimize-autoloader
   composer audit
   ```
   Use the included `composer.lock` for reproducible installation; review and retain it before deployment.
4. Copy `deployment/environment.example.txt` into a private `.env` on the server. The workspace prevented writing `.env.example`, so the template is intentionally stored as plain example text. Set the actual HTTPS `APP_URL`, database settings and operator email privately. Keep `APP_DEBUG=false` and `SESSION_SECURE_COOKIE=true` in production.
5. Generate `LEAD_ADMIN_PASSWORD_HASH` with PHP `password_hash($password, PASSWORD_DEFAULT)` using trusted local tooling without exposing the plaintext in logs or shell history. Store the resulting hash in single quotes in `.env`. Generate an import token using `bin2hex(random_bytes(32))`. Do not send passwords, API keys, tokens or the full `.env` through chat.
6. Ensure `storage/` (including framework/cache/data, sessions, views and logs) and `bootstrap/cache/` are writable by the PHP process. Use minimum necessary ownership/permissions; never solve this with world-writable `777`.
7. Run:
   ```sh
   php artisan key:generate
   php artisan migrate --force
   php artisan config:cache
   php artisan route:list
   php artisan view:cache
   ```
8. For the current sales pipeline, configure verified facts and consent at `/settings/automation` and connect a workflow AI credential privately in n8n. Run `deployment/n8n/setup-sales.ps1` after selecting credential names. The AI secret stays in n8n; the Laravel proposal writer does not use the legacy `OPENAI_API_KEY`/portfolio settings. Rotate the import token if exposed. Rotating the operator password hash invalidates existing dashboard sessions.
9. Complete the QA checklist below before making the system available. Configure backups, privacy/retention rules, HTTPS, monitoring and provider spend limits. On shared hosting, ensure request timeout permits the current synchronous AI call (up to 45 seconds plus application work).

For local HTTP development only, set `APP_ENV=local`, `APP_URL=http://localhost:8000` and `SESSION_SECURE_COOKIE=false`, then use `php artisan serve`. Do not carry these settings into production.

## Mobile PWA (implemented source, not a delivered APK)

After completing the Laravel installation above, serve the app and its assets on the **same HTTPS origin**, with `public/` as document root. Do not point `ASSET_URL` at a separate CDN for these PWA assets. Apache configuration includes the manifest MIME type and worker revalidation headers; verify the actual server honors them. HTTPS is required outside localhost.

- Android/Chromium: visit the app, then use **Install app** when the browser offers it, or the browser's installation menu. Availability depends on browser policy; the button is deliberately hidden until a real install event is available.
- iPhone/iPad: open in Safari → Share → Add to Home Screen. This is a PWA, not an App Store binary.
- Log in using the configured operator account. Installation does not bypass login or make AI free.
- Offline mode only displays a generic reconnect screen. Lead browsing, saving, importing and AI require connectivity. Unsaved form edits are not persisted or queued; a lost response is not proof that a save failed, so check the record after reconnecting.
- The service worker stores only the five explicit public assets listed in `public/sw.js`. It never writes dynamic dashboard/login/API responses into Cache Storage. Server no-store headers provide additional HTTP-cache protection; verify those headers on the deployed app. This is not a guarantee against OS screenshots, extensions or shared-device access.
- Increment `CACHE_NAME` in `public/sw.js` whenever cached shell assets change. Updates wait for existing tabs to close rather than interrupting editing. Clearing site data/uninstalling removes local app data; login sessions still require server-side management.
- `mobile/` contains only earlier Gradle placeholders: there is no Android app module, Gradle wrapper, signing configuration or APK. Do not treat that directory as a buildable native app.

### Required device QA before launch

1. Check manifest and worker return correct files/MIME types over HTTPS, and that no proxy/CDN caches private routes.
2. Install on Android and iOS; verify icons, standalone launch, login/logout and touch layout on actual devices.
3. Open a nested lead route, disconnect and reload. Verify generic offline screen, stylesheet and inbox link; no lead data should be present in Cache Storage.
4. Reconnect, log out, and use browser Back/reopen the app. Verify authentication is rechecked and private content does not remain accessible.
5. Disconnect during save/AI requests: verify no automatic retry, draft duplication or background requests. Review the saved record after reconnecting.
6. Change the worker cache version in staging and verify old shell caches are removed after all tabs close and the new worker activates.

## Approved source setup

Public availability is not automation permission. Review current API terms, rate limits, allowed purposes, redistribution/storage rules and any user eligibility requirements. No login/CAPTCHA bypass is provided.

After approval, add the source in `config/leadgen.php`:

```php
'sources' => [
    'reviewed-board' => [
        'permitted' => true,
        'hosts' => ['actual-listing-host.example'],
    ],
],
```

Hosts must match the listing URL exactly, lowercased. Do not enable the example host from the n8n template as a live source. Never treat AI output or text from a listing as authorization to change this configuration. The app stores listing URLs; it does not fetch them, preventing listing-controlled server-side requests.

## Import API

`POST /api/leads/import`

Headers:

```text
Authorization: Bearer <private LEAD_IMPORT_TOKEN>
Accept: application/json
Content-Type: application/json
```

Example shape (illustrative, not a real lead):

```json
{
  "source": "reviewed-board",
  "source_id": "project-123",
  "url": "https://actual-listing-host.example/project-123",
  "title": "Laravel website with payment integration",
  "description": "Project requirements from the permitted source, with sensitive information removed.",
  "budget_min": 1000,
  "budget_max": 2500,
  "currency": "USD",
  "published_at": "2026-01-01T10:00:00Z"
}
```

Budget and publication date are optional. Currency (three uppercase letters) is mandatory when a budget is supplied; budget units/payment periods are not modeled in this starter. Status, AI scores and proposals cannot be assigned by the import API.

- `201`: created; response includes ID and keyword match score.
- `200` with `duplicate: true`: same source ID or normalized URL already exists; stored review/draft is not overwritten.
- `401`: missing/invalid import token (also fails closed if configured token is too short).
- `422`: validation or source/host approval error.
- `429`: rate limit (60 imports/minute globally).

Duplicate normalization removes tracking parameters and URL fragments, sorts query pairs and folds HTTP/HTTPS into the same listing identity. It does not infer duplicates across different boards or distinct paths; those require future review/merging logic. Source changes on an existing listing do not update the stored content automatically.

## n8n

Import `n8n/lead-import.template.json`. It is inactive and starts manually. It contains sample data and an example destination only; it is not a working job-board connector.

1. Replace sample data with mapping from a specifically approved API/feed.
2. Set the real Laravel endpoint.
3. Add/select an **HTTP Header Auth** credential with header `Authorization`, value `Bearer <private token>`; never embed credentials in exported workflow JSON.
4. Test one listing and a duplicate retry. Configure batching below the API limit; add provider-compliant scheduling, backoff, pagination and an error workflow before enabling collection.
5. Keep execution-data retention minimal. This template disables saving normal/error execution data, which reduces sensitive-data retention but means separate redacted alerts/metrics are needed for production diagnostics.

The local n8n 2.45.4 instance runs in Docker at http://localhost:5679/. Its Assistant sandbox service (1.12.0) is installed with a private URL/key connection. Health, authentication, actual isolated command execution and cleanup passed, and n8n can reach the authenticated API from inside its container. Existing n8n data was retained with a backup. Sign in again at the new port. Laravel workflow integration, model-provider calls, source approval and live lead collection remain unverified. See deployment/n8n/README.md for restart and storage details. SearXNG web search is connected at http://localhost:8082/; a real JSON search from inside n8n returned 37 results.

## AI and privacy

Keyword score and AI fit score are separate; neither is a probability of winning work. Keyword matching is only an initial filter and can match a negative requirement such as “no Laravel.” The operator must review intent, eligibility, budget and source rules.

On explicit request, listing title, description, budget and the configured verified business profile go to OpenAI. URLs/source IDs are not sent by this service, but descriptions may themselves contain personal information: remove it upstream and confirm rights to transmit data. AI cost and provider retention policies apply. The system cannot guarantee factual or injection-proof output; it treats listing instructions as untrusted, has no AI tools or URL fetching, validates the response, escapes content in Blade and requires human review. Drafts and lead details persist in MySQL; there is no automatic deletion policy yet. Logs/backups also need private access and retention limits.

## Release-blocking QA checklist

- Install dependencies; run Composer audit; record lockfile; boot Laravel and compile all Blade views.
- Run migrations against a dedicated test MySQL database; test rollback only there (it deletes leads).
- Verify anonymous dashboard requests redirect, blank credentials fail closed, login rate limit works, session regenerates, logout invalidates, and CSRF rejects unsafe requests.
- Test missing/incorrect API tokens; disallowed sources/hosts; invalid budgets, dates and credential-bearing URLs; status/AI field injection; duplicate and concurrent requests; 429 behavior.
- Test all dashboard filters, empty/no-result states, pagination and status/proposal editing.
- Test markup/script payloads in title/description/proposal render as text rather than executable content.
- Mock successful, malformed, truncated, timed-out and rejected AI responses. Test missing configuration, consent, existing drafts and concurrent edits. Confirm no unexpected API charges or secret-bearing logs.
- Review mobile/desktop layout in a browser. Confirm only `public/` is exposed and `.env`, storage, config and vendor are inaccessible.
- Test a permitted live source and the actual n8n version. Confirm unapproved/edited messages never dispatch, each follow-up requires separate approval, and ambiguous delivery cannot automatically retry.

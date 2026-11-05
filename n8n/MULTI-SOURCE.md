# Local multi-source collection

The installed workflow is **Lead Generator - Multi-source collection**. It uses
the local import credential encrypted by n8n. Tokens are absent from the workflow
JSON. Its independent branches are:

| Source | Collection method | Qualification |
| --- | --- | --- |
| Remotive | Official software jobs API | Explicit contract/freelance developer or integration roles, last 30 days |
| Remote OK | Official public API | Explicit contract/freelance roles, last 30 days; source name and follow backlink retained |
| Arbeitnow | Official public API, first page | Remote contract/freelance roles, last 30 days |
| Jobicy | Free public engineering jobs API | Contract roles only, with original application links. A batched status check hides confirmed closures. |
| We Work Remotely | Official public RSS | Contract roles only, with original listing links and feed expiration. |
| Himalayas | Free public contractor search API | Contract roles only, with source application links and feed expiration. At most one search per rolling day. |
| Facebook public | SearXNG indexed post snippets | Explicit service-request intent and post URLs |
| Instagram public | SearXNG indexed post/reel snippets | Explicit service-request intent and post URLs |
| Public web | SearXNG, Reddit/LinkedIn/Hacker News | Hiring/project requests; profile pages and general programming discussions excluded |

Search discovery is incomplete and snippets can be stale. Search dates are not
invented. A match is a candidate for review, never proof that someone will buy.
Zero relevant leads is a valid result. Job-board employment opportunities are
separate from confirmed client inquiries. No contact extraction, automated
messages, applications, or AI proposals run during collection.

## Source control and run history

`config/leadgen.php` controls exact listing hosts and per-source approval flags.
Keep a flag false until that source's use has been approved and reviewed. The
source gateway enforces **four fetch attempts per rolling 24 hours per source**,
including manual tests and failed attempts. Immediate repeats have a one-minute
cooldown. Jobicy waits at least one hour between collection passes, and Himalayas waits 24 hours. The normal schedule is every six hours in Asia/Karachi. Requests are
not aggressively retried. Search topics rotate between runs.

`GET /api/collection/sources`, `POST /api/collection/{source}/claim`, and
`POST /api/collection/runs/{id}/complete` all require the import bearer token.
Only approved claims produce a fetch URL. Batches contain at most 30 items and
reuse the existing per-item validation, host restrictions, matching, and
deduplication. Completed batches are idempotent. Durable collection history
stores counts and safe error codes, not credentials or raw provider payloads.
Payloads are not saved in n8n execution history. Code nodes import only the
allowed Node `url` builtin; no external modules are enabled.

The dashboard has a source filter, connection status and recent run counts.
Rejected discoveries remain available under the Rejected status and are hidden
from the default active inbox. They are preserved rather than silently deleted.

## Start and reconnect

Run from the project directory:

```powershell
.\deployment\start-local.ps1
.\deployment\n8n\start-sandbox.ps1
```

The dashboard stays at `http://127.0.0.1:8079/`. The Docker-facing PHP listener
binds **only** to the private WSL interface on port 8083 and serves ingestion and
health paths; it does not expose the dashboard. n8n's existing owner, credentials
and unrelated workflows are retained. Both Docker and the local services must
be running and the laptop awake for scheduled runs.

If the WSL interface address changes:

```powershell
.\deployment\n8n\setup-collector.ps1
```

This backs up n8n, reuses the private token and stable collector IDs, regenerates
and imports only the collector workflow **inactive**, and starts the bridge.
Custom collector edits belong in `build-multi-source-workflow.cjs` because setup
regenerates its graph. Test, then publish it again in the n8n editor. Existing
approval settings are preserved. `-EnableReviewedPublicSources` explicitly
enables the six reviewed public connectors; it is not a Meta account connection.
Plaintext credential-transfer files are deleted after import.

Check safe metadata with `php deployment/n8n/collector-status.php`. Do not print
`.env`, private credential files, or full n8n configuration. If the operator
account is still empty, open `http://127.0.0.1:8079/setup/operator` and choose
your own email and password. This first-owner form exists only for a local
loopback installation with no owner and cannot reset an existing account.
The interactive `php artisan leadgen:setup-operator` command remains available.

After sign-in, open **Connections** at `/settings/connections`. It persistently
stores your Facebook Page URL/ID, Instagram username/account ID, Meta App ID,
public HTTPS webhook URL, and optional n8n credential display name. Partial
details can be saved. CSRF and operator authentication protect writes; invalid
URLs and submitted credential fields are rejected. Access tokens, app secrets
and passwords belong only in n8n's private credential form. Saving account
identifiers never enables private intake or changes source approvals.

On 31 October 2026, the user explicitly approved automatic collection every
six hours. The workflow was published through the n8n editor and its persisted
active flag and active version were verified. This approval covers the six
public-source branches; private Meta intake is still pending the setup below.

## Facebook/Instagram private messages and comments: pending

Public search does **not** access private groups, account inboxes or arbitrary
users' comments. The separate `facebook-inbox` and `instagram-inbox` source
entries remain disabled. There is no saved Meta credential in this installation.
Completing private intake requires:

1. Your Facebook Page and Instagram professional account identifiers and a
   Meta Developer app with the appropriate approved read permissions.
2. Credentials entered privately into n8n, including the real app secret; never
   send passwords, tokens or app secrets through chat.
3. A user-approved public HTTPS webhook URL. The current localhost URL cannot
   receive Meta's external events. No public tunnel has been created.
4. Configure, validate signatures and test real permitted Page/Instagram events,
   then map only relevant incoming requests to the import schema. Page messages
   and comments need their matching supported API/events and granted scopes;
   the native Page feed trigger alone is not universal inbox access.

The installed native Facebook Trigger supports Instagram `comments` and
`messages`; its Page object supports `feed`. Instagram requires Include Values.
These account connectors have not been activated or end-to-end verified.

References: [Remotive API terms](https://github.com/remotive-com/remote-jobs-api),
[Remote OK API attribution](https://remoteok.com/api),
[Arbeitnow API](https://www.arbeitnow.com/blog/job-board-api),
[n8n Instagram trigger](https://docs.n8n.io/integrations/builtin/trigger-nodes/n8n-nodes-base.facebooktrigger/instagram),
[n8n Code modules](https://docs.n8n.io/deploy/host-n8n/configure-n8n/basic-configuration/configuration-examples/enable-modules-in-code-node.md).

# Local n8n and Assistant sandbox

Use Docker Desktop in Linux-container mode, then run from the project folder:

```powershell
.\deployment\n8n\start-sandbox.ps1
```

The editor is configured at http://localhost:5679/ and the sandbox API at
http://127.0.0.1:8081. Port 5678 belongs to the pre-existing History Studio
container; that instance was not reconfigured. All published ports of this
stack bind to Windows loopback only. No public tunnel or Windows startup task
is configured.

## Runtime and data

The Compose project is `leadgenerator-sandbox`. It pins n8n 2.45.4 and the
matching API, Docker-in-Docker runner and sandbox execution images at 1.12.0.
The runner is privileged inside Docker Desktop's Linux host, as required by
n8n's official local development setup; keep it local.

Existing n8n data remains in `storage/n8n/data/.n8n/`, mounted at
`/home/node/.n8n` in the n8n container. This retains the SQLite database and
credential-encryption configuration. A complete backup from before migration
is in `storage/n8n/data-backups/before-docker-20261028042457/.n8n/`.
Back up this entire directory with n8n stopped. Do not run the portable and
container servers against this database at the same time.

Dedicated Docker volumes retain the sandbox CA, per-service TLS material,
API state, and runner's inner image store. Runtime API/runner traffic uses
mutual TLS. Only each service's own TLS directory is mounted into it; the CA
private key stays in the initialization volume. No host Docker socket,
application files or model-provider credentials are mounted into the runner.

## Private service credentials

The initializer generates three independent random service secrets once, keeps
them under the ignored `storage/n8n/sandbox/` directory, and never prints them.
Windows ACLs restrict this directory to the current user and SYSTEM.

The sandbox key is independent of a model-provider API key. The startup helper
passes only the necessary variables to each service. n8n reaches the API
through `http://sandbox-api:8080` inside Docker; the Windows host reaches it
through `http://127.0.0.1:8081`. The helper does not import provider keys from
the shell or Laravel environment. Previously saved n8n credentials remain in
the existing database and encryption configuration.

## Verification and restart

`start-sandbox.ps1` checks Docker and Compose configuration, starts the API and
runner, preloads the matching execution image, then runs `check-sandbox.cjs`.
That real check verifies health, rejected unauthenticated access, creation of
an ephemeral sandbox with no outbound network access, command output and a
successful completed exit event, and cleanup. Only after passing does the
helper start the n8n container.

Once Docker mode has been activated, `start-local.ps1` delegates to this helper.
The original Node v24.22.1/npm runtime is retained as a fallback; it is not the
active server. `launch-local.cjs` also supports the verified private sandbox
configuration for that fallback. Windows module loading was too slow during
this continuation, so the same n8n version was moved into Docker.

Docker startup was repaired by retaining backups of directories containing only
stale IPC sockets: `%LOCALAPPDATA%/Docker/run.stale-*` and
`%LOCALAPPDATA%/docker-secrets-engine.stale-*`. Container data and settings were
retained.

## Scope

Sandbox execution is separate from model-provider connectivity, Python Code
task-runner configuration, Laravel import integration, source approval and
automatic lead collection. No LLM request or live lead import is part of the
sandbox smoke check. The Remotive workflow remains inactive and source
approval must be completed before collection.

References: [official Assistant setup](https://docs.n8n.io/deploy/host-n8n/configure-n8n/set-up-n8n-assistant#setup-2-self-host-the-sandbox-manually-advanced)
and [sandbox configuration](https://github.com/n8n-io/n8n-sandbox-service/blob/main/docs/configuration.md).


## Verified on 28 October 2026

- Docker Desktop engine reports 29.6.1 after the reversible IPC repairs.
- The sandbox smoke check passed, including real command execution and cleanup.
- The authenticated sandbox API is reachable from inside the n8n container,
  and reports a healthy runner. Service key values were not printed.
- Existing account/model settings and credentials were retained in the same data
  directory, with the pre-migration backup described above.
- An existing persisted `sandboxEnabled=false` override was changed to `true`
  while n8n was stopped. Only this flag was changed. The original settings row
  was retained privately in `storage/n8n/sandbox/settings-before-sandbox-enable.json`.
  `enable-sandbox-setting.cjs` is a maintenance helper for that one setting; do
  not run it while n8n is running.
- Browser navigation reaches the sign-in page at the new port; the user must
  sign in themselves. No login credential was read, reset or supplied.
- LLM requests and Python Code task-runner execution were not tested. The
  container reports that its internal Python runner is not configured.

## Local account recovery (28 October 2026)

The single local owner account was returned to its initial setup state using
n8n's official `user-management:reset` command. A verified complete private
backup is under `storage/n8n/data-backups/before-account-recovery-*/.n8n/`.
Before and after recovery, the database had zero workflows and one saved
credential. The owner record remains and awaits a new email/password at
http://localhost:5679/setup. The user must enter and submit these fields.
No password or saved provider secret was displayed or supplied by the agent.

## SearXNG web search

SearXNG is installed and connected to the local n8n instance. Open the search
interface at http://localhost:8082/ and n8n Assistant at
http://localhost:5679/assistant. Both services bind only to Windows loopback.
Inside Docker, n8n uses `N8N_INSTANCE_AI_SEARXNG_URL=http://searxng:8080`.

`search.compose.yaml` merges with `sandbox.compose.yaml`; the restart helper
loads both. The official Docker Hub image already installed on this machine
was verified and pinned as:
`searxng/searxng@sha256:0f48ffe2152f388f5eaede27f96b610449cebfc890d0ad457304475a54ad7b6e`.
The earlier GHCR download is not required by the final setup.

Search settings enable HTML and JSON formats. Persistent configuration and
cache live in dedicated Docker volumes. The service has a generated private
signing secret in the ignored `service.env`; n8n does not need a SearXNG API
key. No model credential was read or changed for this integration.

The configured engines are Bing, Brave, DuckDuckGo and Wikipedia. Google was
omitted after it was unavailable during the initial test. The final real
`n8n documentation` search, requested from inside the n8n container using its
configured URL, returned 37 usable results and no unavailable engines.
Upstream search availability can change.

The restart helper's sandbox, search-health and cleanup checks passed. For a
manual live search check, run the portable Node executable with
`deployment/n8n/check-search.cjs --live`. Assistant UI verification requires the
user's signed-in browser; final verification here used Docker and HTTP checks.
No LLM request or automatic lead collection was performed.

The inactive Windows npm fallback was safely moved to
`D:\CodexCache\leadGenerator-n8n\portable-runtime-20261028` to free C-drive
space. Its entry exists there; the original runtime directory is gone.
`storage/n8n/runtime-location.json` and fallback launch helpers reference the
new location. The portable Node executable remains under `storage/n8n/node/`.

Final checks: SearXNG reports `2026.10.27+b5ed73fcb`; n8n's container health is
`healthy` and the host readiness endpoint returns HTTP 200.

## Live Assistant backend verification (29 October 2026)

The user approved reuse of the existing saved model connection. The native
backend test returned the exact model response `N8N_AI_OK` in 7,647 ms with
finish reason `stop`. Native sandbox verification passed in 4,056 ms and the
native search verifier returned 10 results. No workflow or outreach was created.
The saved key and model/provider settings were not changed.

`test-assistant.cjs` initializes the same credential policies as the Assistant
module before resolving the saved connection. The first incomplete harness
fell back to an unconfigured Anthropic model; this was corrected in the test
helper and was not an app-configuration fault.

The final test uses n8n's model resolver/SDK and native sandbox/search verifiers.
It confirms live backend behavior; browser-chat interaction was not tested
because browser control was unavailable. Safe result metadata is retained in
`storage/n8n/logs/assistant-smoke-test.json`. The temporary container helper was
removed after the test; the reusable source remains in `deployment/n8n/`.

Docker Desktop had to be restored after the same stale IPC socket startup
failure. Both runtime socket directories were backed up while the Docker WSL
host was stopped, then Docker was restarted. Container storage was retained.
This recovery does not establish that the startup issue cannot recur.

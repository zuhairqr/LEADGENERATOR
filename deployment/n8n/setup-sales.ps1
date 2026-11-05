$ErrorActionPreference = 'Stop'
Set-Location (Resolve-Path (Join-Path $PSScriptRoot '../..'))
$node = Join-Path (Get-Location) 'storage/n8n/node/node-v24.22.1-win-x64/node.exe'
& $node deployment/n8n/backup-before-collector.cjs
if ($LASTEXITCODE) { throw 'Backup failed' }
& "$PSScriptRoot/start-import-bridge.ps1"
php deployment/n8n/prepare-sales.php
if ($LASTEXITCODE) { throw 'Configuration export failed' }
& $node n8n/build-sales-workflows.cjs
if ($LASTEXITCODE) { throw 'Workflow build failed' }
& $node n8n/build-multi-source-workflow.cjs
if ($LASTEXITCODE) { throw 'Collection build failed' }
& $node deployment/n8n/import-sales.cjs
if ($LASTEXITCODE) { throw 'Workflow publication failed' }
# Only this project's n8n is restarted; unrelated containers and data are preserved.
docker restart leadgenerator-sandbox-n8n-1
if ($LASTEXITCODE) { throw 'Project n8n restart failed' }
& $node deployment/n8n/verify-sales.cjs
if ($LASTEXITCODE) { throw 'Publication check failed' }

param([switch]$EnableReviewedPublicSources)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location -LiteralPath $projectRoot
& "$projectRoot/deployment/start-local.ps1"
$addresses = @(Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.InterfaceAlias -match 'WSL' -and $_.IPAddress -match '^172\.' })
if ($addresses.Count -ne 1) { throw 'Expected one private WSL interface.' }
$node = Join-Path $projectRoot 'storage/n8n/node/node-v24.22.1-win-x64/node.exe'
& $node "$PSScriptRoot/backup-before-collector.cjs"
if ($LASTEXITCODE -ne 0) { throw 'Backup failed.' }
$prepareArgs = @("$PSScriptRoot/prepare-collector.php", $addresses[0].IPAddress)
if ($EnableReviewedPublicSources) { $prepareArgs += '--approve-reviewed-public-sources' }
& php @prepareArgs
if ($LASTEXITCODE -ne 0) { throw 'Private preparation failed.' }
& $node "$projectRoot/n8n/build-multi-source-workflow.cjs"
if ($LASTEXITCODE -ne 0) { throw 'Workflow build failed.' }
& $node "$PSScriptRoot/import-collector.cjs"
if ($LASTEXITCODE -ne 0) { throw 'n8n import failed.' }
& "$PSScriptRoot/start-import-bridge.ps1"
Write-Output 'Collector imported inactive. Run one manual test, inspect Laravel source counts, then publish in n8n.'
Write-Output 'This regenerates only the named collector workflow. Keep custom changes in its builder.'

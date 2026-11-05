$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$runtime = Join-Path $project 'storage/n8n'
$versions = Get-Content -LiteralPath "$PSScriptRoot/runtime-version.json" -Raw | ConvertFrom-Json
$nodeExecutable = Join-Path $runtime "node/node-$($versions.node)-win-x64/node.exe"
$docker = 'C:\Program Files\Docker\Docker\resources\bin\docker.exe'
& $nodeExecutable "$PSScriptRoot/initialize-sandbox.cjs"
if ($LASTEXITCODE -ne 0) { throw 'Could not initialize sandbox credentials.' }
& $nodeExecutable "$PSScriptRoot/initialize-search.cjs"
if ($LASTEXITCODE -ne 0) { throw 'Could not initialize private search configuration.' }
$secrets = Join-Path $runtime 'sandbox/service.env'
$identity = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
& icacls.exe (Split-Path -Parent $secrets) /inheritance:r /grant:r "*${identity}:(OI)(CI)(F)" '*S-1-5-18:(OI)(CI)(F)' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Could not restrict sandbox directory permissions.' }
& icacls.exe $secrets /inheritance:r /grant:r "*${identity}:(F)" '*S-1-5-18:(F)' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Could not restrict sandbox credential permissions.' }
& $docker info --format '{{.ServerVersion}}'
if ($LASTEXITCODE -ne 0) { throw 'Docker engine is unavailable. Start Docker Desktop and retry.' }
$composeArguments = @('compose','--project-name','leadgenerator-sandbox','--env-file',$secrets,'-f',"$PSScriptRoot/sandbox.compose.yaml",'-f',"$PSScriptRoot/search.compose.yaml")
& $docker @composeArguments config --quiet
if ($LASTEXITCODE -ne 0) { throw 'Invalid sandbox Compose configuration.' }
& $docker @composeArguments up -d tls-init sandbox-api sandbox-runner-1 searxng
if ($LASTEXITCODE -ne 0) { throw 'Sandbox containers could not start.' }
& $docker @composeArguments exec -T sandbox-runner-1 docker pull --quiet n8nio/n8n-sandbox-service-sandbox:1.12.0
if ($LASTEXITCODE -ne 0) { throw 'Sandbox execution image is unavailable.' }
& $nodeExecutable "$PSScriptRoot/check-sandbox.cjs"
if ($LASTEXITCODE -ne 0) { throw 'Sandbox verification failed; n8n configuration was not activated.' }
& $nodeExecutable "$PSScriptRoot/check-search.cjs"
if ($LASTEXITCODE -ne 0) { throw 'Search service verification failed.' }
& $docker @composeArguments up -d n8n
if ($LASTEXITCODE -ne 0) { throw 'Sandbox passed, but the n8n container could not start.' }
New-Item -ItemType File -Path (Join-Path $runtime 'sandbox/docker-enabled') -Force | Out-Null
Write-Output 'Sandbox verified. n8n is starting at http://localhost:5679/.'

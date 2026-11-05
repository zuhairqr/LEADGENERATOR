$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$runtime = Join-Path $project 'storage/n8n'
if (Test-Path -LiteralPath (Join-Path $runtime 'sandbox/docker-enabled')) {
    & "$PSScriptRoot/start-sandbox.ps1"
    exit $LASTEXITCODE
}
$versions = Get-Content -LiteralPath "$PSScriptRoot/runtime-version.json" -Raw | ConvertFrom-Json
$nodeExecutable = Join-Path $runtime "node/node-$($versions.node)-win-x64/node.exe"
$runtimeLocation = Join-Path $runtime 'runtime-location.json'
$npmRuntime = if (Test-Path -LiteralPath $runtimeLocation) { (Get-Content -LiteralPath $runtimeLocation -Raw | ConvertFrom-Json).npmRuntime } else { Join-Path $runtime 'runtime' }
$entry = Join-Path $npmRuntime 'node_modules/n8n/bin/n8n'
if (!(Test-Path -LiteralPath $nodeExecutable) -or !(Test-Path -LiteralPath $entry)) {
    throw 'The pinned local n8n runtime is not installed.'
}
$listener = Get-NetTCPConnection -State Listen -LocalPort 5679 -ErrorAction SilentlyContinue
if ($listener) {
    $response = Invoke-WebRequest -Uri 'http://localhost:5679/healthz/readiness' -TimeoutSec 5 -UseBasicParsing
    if ([int]$response.StatusCode -ne 200) { throw 'Port 5679 is occupied but n8n is not ready.' }
    Write-Output 'n8n is already available at http://localhost:5679/'
    exit 0
}
New-Item -ItemType Directory -Force -Path "$runtime/logs" | Out-Null
$server = Start-Process -FilePath $nodeExecutable -ArgumentList "$PSScriptRoot/launch-local.cjs" `
    -WorkingDirectory $project -WindowStyle Hidden -PassThru `
    -RedirectStandardOutput "$runtime/logs/server.stdout.log" -RedirectStandardError "$runtime/logs/server.stderr.log"
$server.Id | Set-Content -LiteralPath "$runtime/server.pid"
Write-Output 'n8n is starting at http://localhost:5679/ (first startup can take a minute).'
Write-Output 'Logs: storage/n8n/logs/. Create your own owner account in the browser when prompted.'

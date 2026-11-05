$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $projectRoot
if (!(Test-Path -LiteralPath "$projectRoot/.env") -or !(Test-Path -LiteralPath "$projectRoot/vendor/autoload.php")) {
    throw 'Install dependencies and complete the one-time local setup first.'
}
function Test-LocalPort([int] $Port) {
    $client = [System.Net.Sockets.TcpClient]::new()
    try { return $client.ConnectAsync('127.0.0.1', $Port).Wait(500) -and $client.Connected }
    catch { return $false }
    finally { $client.Dispose() }
}
$runtime = Join-Path $projectRoot 'storage/local-runtime'
New-Item -ItemType Directory -Force -Path $runtime | Out-Null
if (!(Test-LocalPort 3310)) {
    $mysqlBase = 'C:/xampp/mysql'
    $projectPath = $projectRoot.Replace('\', '/')
    Start-Process -FilePath "$mysqlBase/bin/mysqld.exe" -ArgumentList @(
        '--no-defaults', "--basedir=$mysqlBase", "--datadir=$projectPath/storage/local-mysql",
        '--port=3310', '--bind-address=127.0.0.1', '--innodb-buffer-pool-size=64M',
        '--skip-name-resolve', '--skip-host-cache',
        "--log-error=$projectPath/storage/local-mysql/server.log", "--pid-file=$projectPath/storage/local-mysql/server.pid"
    ) -WindowStyle Hidden | Out-Null
    for ($attempt = 0; $attempt -lt 20 -and !(Test-LocalPort 3310); $attempt++) { Start-Sleep -Milliseconds 500 }
    if (!(Test-LocalPort 3310)) { throw 'Dedicated MariaDB did not start; check storage/local-mysql/server.log.' }
}
if (!(Test-LocalPort 8079)) {
    $phpExecutable = (Get-Command php.exe).Source
    Start-Process -FilePath $phpExecutable -ArgumentList @('artisan', 'serve', '--host=127.0.0.1', '--port=8079', '--tries=1') `
        -WorkingDirectory $projectRoot -WindowStyle Hidden `
        -RedirectStandardOutput "$runtime/http.stdout.log" -RedirectStandardError "$runtime/http.stderr.log" | Out-Null
    for ($attempt = 0; $attempt -lt 20 -and !(Test-LocalPort 8079); $attempt++) { Start-Sleep -Milliseconds 500 }
    if (!(Test-LocalPort 8079)) { throw 'Laravel did not start; check storage/local-runtime/http.stderr.log.' }
}
Write-Output 'Local services are available. Open http://127.0.0.1:8079/login'
Write-Output 'For first login, run: php artisan leadgen:setup-operator'
if (Test-Path -LiteralPath "$projectRoot/storage/n8n/lead-collector/connection.json") {
    & "$projectRoot/deployment/n8n/start-import-bridge.ps1"
}

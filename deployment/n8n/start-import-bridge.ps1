$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$addresses = @(Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.InterfaceAlias -match 'WSL' -and $_.IPAddress -match '^172\.' })
if ($addresses.Count -ne 1) { throw 'Expected one private WSL interface; start Docker Desktop and check its network.' }
$address = $addresses[0].IPAddress
$runtime = Join-Path $projectRoot 'storage/local-runtime'
New-Item -ItemType Directory -Force -Path $runtime | Out-Null
foreach ($service in @(@{ Port=8083; Router='import-router.php'; Log='import-http' }, @{ Port=8084; Router='envelope-router.php'; Log='envelope-http' })) {
    $port=$service.Port
    $listener = Get-NetTCPConnection -LocalAddress $address -LocalPort $port -State Listen -ErrorAction SilentlyContinue
    if ($listener) {
        $owner=Get-CimInstance Win32_Process -Filter "ProcessId=$($listener.OwningProcess)"
        if (!$owner -or $owner.Name -ne 'php.exe' -or $owner.CommandLine -notmatch [regex]::Escape($service.Router)) { throw "Private bridge port $port belongs to another process; no process was changed." }
    } else {
        $router=Join-Path $PSScriptRoot $service.Router
        $public=Join-Path $projectRoot 'public'
        Start-Process -FilePath (Get-Command php.exe).Source -ArgumentList @('-S',($address+':'+$port),'-t',$public,$router) `
            -WorkingDirectory $projectRoot -WindowStyle Hidden -RedirectStandardOutput "$runtime/$($service.Log).stdout.log" -RedirectStandardError "$runtime/$($service.Log).stderr.log" | Out-Null
        for ($attempt=0; $attempt -lt 20; $attempt++) {
            if (Get-NetTCPConnection -LocalAddress $address -LocalPort $port -State Listen -ErrorAction SilentlyContinue) { break }
            Start-Sleep -Milliseconds 250
        }
        if (!(Get-NetTCPConnection -LocalAddress $address -LocalPort $port -State Listen -ErrorAction SilentlyContinue)) { throw "Private bridge port $port did not start. Check its local runtime log." }
    }
}
Write-Output "Private import bridge: http://${address}:8083; isolated approved-envelope check: http://${address}:8084."

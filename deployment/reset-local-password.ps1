$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath (Split-Path -Parent $PSScriptRoot)
$Host.UI.RawUI.WindowTitle = 'Lead Generator - Private password reset'
$statusPath = Join-Path (Get-Location) 'storage/local-runtime/password-reset-status.json'
@{ state = 'awaiting_password'; checked_at = [DateTime]::UtcNow.ToString('o') } | ConvertTo-Json | Set-Content -LiteralPath $statusPath -Encoding UTF8
php artisan leadgen:reset-password
$resetExit = $LASTEXITCODE
@{ state = $(if ($resetExit -eq 0) { 'completed' } else { 'failed' }); checked_at = [DateTime]::UtcNow.ToString('o') } | ConvertTo-Json | Set-Content -LiteralPath $statusPath -Encoding UTF8
if ($resetExit) { Write-Host 'Password was not changed. Run this window again to retry.' }

<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Http\Kernel::class)->bootstrap();

use App\Models\{ConnectorConfig, ConnectorRun, IntegrationSecret, Lead};
use App\Services\{ConnectorFailure, ConnectorService};

if (config('app.env') !== 'local' || config('database.connections.mysql.host') !== '127.0.0.1'
    || (int) config('database.connections.mysql.port') !== 3310) {
    fwrite(STDERR, "Dedicated local database required.\n");
    exit(2);
}

$connector = ConnectorConfig::where('platform', 'Apollo.io')->where('purpose', 'feed')->latest()->first();
if (!$connector) { echo "Apollo connector: not configured. No request made.\n"; exit(0); }
$hasKey = IntegrationSecret::whereKey('cn_'.str_replace('-', '', $connector->id))->exists();
if (!$hasKey) { echo "Apollo connector: private API key not saved. No request made.\n"; exit(0); }
$latest = ConnectorRun::where('connector_id', $connector->id)->latest()->first();
if (in_array('--status', $argv, true)) {
    echo 'Apollo connector: '.($connector->enabled ? 'enabled' : 'disabled').'; key saved privately; last status '
        .($latest?->status ?? 'none').'; last error '.($latest?->error_code ?? 'none').". No request made.\n";
    exit(0);
}
if (in_array('--health', $argv, true)) {
    try {
        $headers = app(ConnectorService::class)->headers($connector);
        $response = app(\App\Services\PublicWebClient::class)->fetch('https://api.apollo.io/api/v1/auth/health', 65536, $headers);
        echo 'Apollo key health: HTTP '.(int) $response['status'].". No leads imported.\n";
    } catch (ConnectorFailure $e) {
        echo 'Apollo key health: '.$e->getMessage().". No leads imported.\n";
    } catch (\Throwable $e) {
        echo "Apollo key health: network or endpoint error. No leads imported.\n";
    }
    exit(0);
}
$attempts = ConnectorRun::where('connector_id', $connector->id)->where('created_at', '>', now()->subHours(24))->count();
if ($attempts >= 4) { echo "Apollo connector: daily attempt limit reached. No request made.\n"; exit(0); }

$before = Lead::count();
try {
    $result = app(ConnectorService::class)->testConnection($connector);
    echo 'Apollo authenticated test: HTTP '.$result['http_status'].'; rows '. $result['people_count'].".\n";
} catch (ConnectorFailure $e) {
    echo 'Apollo authenticated test: '.$e->getMessage().".\n";
}
echo 'Lead count unchanged: '.(Lead::count() === $before ? 'yes' : 'NO').".\n";

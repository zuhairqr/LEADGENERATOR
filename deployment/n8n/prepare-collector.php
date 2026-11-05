<?php
// Local setup only. Token values are written privately, never printed.
declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('local')) throw new RuntimeException('Local environment required.');
$address = $argv[1] ?? '';
if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $address)) throw new RuntimeException('Expected private WSL interface address.');
$root = dirname(__DIR__, 2);
$directory = $root.'/storage/n8n/lead-collector';
if (!is_dir($directory)) mkdir($directory, 0700, true);
$envPath = $root.'/.env';
if (is_link($envPath)) throw new RuntimeException('Refusing symlink environment.');
$text = file_get_contents($envPath);
$backup = $directory.'/environment-before-collector.env';
if (!file_exists($backup)) file_put_contents($backup, $text, LOCK_EX);
$token = (string) config('leadgen.import_token');
if (strlen($token) < 32) $token = bin2hex(random_bytes(32));
if (!preg_match('/^[a-zA-Z0-9_-]{32,}$/', $token)) throw new RuntimeException('Existing token format needs private review; nothing has been rotated.');
$values = ['LEAD_IMPORT_TOKEN' => '"'.$token.'"',
    'LEAD_REMOTIVE_APPROVED' => 'true', 'LEAD_REMOTEOK_APPROVED' => 'true',
    'LEAD_ARBEITNOW_APPROVED' => 'true', 'LEAD_FACEBOOK_PUBLIC_APPROVED' => 'true',
    'LEAD_INSTAGRAM_PUBLIC_APPROVED' => 'true', 'LEAD_PUBLIC_WEB_APPROVED' => 'true'];
foreach ($values as $key => $value) {
    $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
    if ($key !== 'LEAD_IMPORT_TOKEN' && !in_array('--approve-reviewed-public-sources', $argv, true)) {
        if (preg_match($pattern, $text)) continue;
        $value = 'false';
    }
    $line = $key.'='.$value;
    $text = preg_match($pattern, $text) ? preg_replace_callback($pattern, fn () => $line, $text) : rtrim($text)."\n".$line."\n";
}
file_put_contents($envPath, $text, LOCK_EX);
$connectionPath = $directory.'/connection.json';
$connection = file_exists($connectionPath) ? json_decode(file_get_contents($connectionPath), true, 512, JSON_THROW_ON_ERROR) : [
    'credentialId' => 'leadgenLocalImport'.bin2hex(random_bytes(4)),
    'workflowId' => 'leadgenMulti'.bin2hex(random_bytes(4)),
];
$connection['baseUrl'] = 'http://'.$address.':8083';
file_put_contents($connectionPath, json_encode($connection, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
$credentials = [['id' => $connection['credentialId'], 'name' => 'Lead Generator local import',
    'type' => 'httpHeaderAuth', 'data' => ['name' => 'Authorization', 'value' => 'Bearer '.$token]]];
file_put_contents($directory.'/credential-import.private.json', json_encode($credentials, JSON_THROW_ON_ERROR), LOCK_EX);
chmod($directory.'/credential-import.private.json', 0600);
Illuminate\Support\Facades\Artisan::call('config:clear');
echo "Prepared local import credential and approved public-source configuration. Secret values were not printed.\n";

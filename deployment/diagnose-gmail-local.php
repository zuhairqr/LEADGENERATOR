<?php
// Bounded, non-sending probe through the existing authenticated Gmail workflow.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('local')) throw new RuntimeException('Local environment required.');
$base = rtrim(config('leadgen.sales_bridge'), '/');
if ($base !== 'http://127.0.0.1:5679/webhook') throw new RuntimeException('Unexpected local workflow destination.');
$result = ['emails_sent'=>0];
try {
    $response = Illuminate\Support\Facades\Http::withToken(config('leadgen.import_token'))
        ->acceptJson()->connectTimeout(5)->timeout(80)->post($base.'/leadgen-gmail-profile', []);
    $json = $response->json();
    $result += ['http_status'=>$response->status(), 'json_response'=>is_array($json),
        'ok'=>($json['ok']??false)===true, 'read_access'=>($json['read_access']??false)===true];
    $code = $json['error_code']??null;
    $result['error_code'] = is_string($code) && preg_match('/^[A-Z][A-Z0-9_]{0,99}$/D', $code) ? $code : null;
    if (!empty($json['emailAddress'])) {
        $sender = app(App\Services\ProspectingProfile::class)->sender();
        $result['sender_matches'] = strcasecmp($sender['from_email']??'', $json['emailAddress'])===0;
    }
} catch (Illuminate\Http\Client\ConnectionException $e) {
    $result['error_code'] = 'LOCAL_WORKFLOW_CONNECTION_FAILED';
} catch (Throwable $e) {
    $result['error_code'] = 'LOCAL_WORKFLOW_PROBE_FAILED';
}
echo json_encode($result, JSON_UNESCAPED_SLASHES).PHP_EOL;

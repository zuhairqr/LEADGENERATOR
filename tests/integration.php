<?php
/** Real Laravel HTTP-kernel + isolated MariaDB checks. No provider requests or real source imports. */
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Models\Lead;

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (config('app.env') !== 'local' || config('database.connections.mysql.port') != 3310
    || config('database.connections.mysql.host') !== '127.0.0.1') {
    throw new RuntimeException('Integration checks require the dedicated local server on port 3310.');
}
$jobicySource = config('leadgen.sources.jobicy');
config(['database.connections.mysql.database' => 'lead_generator_test',
    'cache.stores.file.path' => storage_path('local-runtime/test-cache-'.bin2hex(random_bytes(6))),
    'cache.stores.file.lock_path' => storage_path('local-runtime/test-locks'),
    'leadgen.admin_email' => 'operator@example.test',
    'leadgen.admin_password_hash' => password_hash('Ephemeral-test-password-only', PASSWORD_DEFAULT),
    'leadgen.import_token' => bin2hex(random_bytes(32)),
    'leadgen.openai_key' => '', 'leadgen.portfolio' => '',
    'leadgen.sources' => ['test-board' => ['permitted' => true, 'kind' => 'remotive', 'fetch_url' => 'https://listings.example.test/feed', 'hosts' => ['listings.example.test']]],
]);
Http::preventStrayRequests();
DB::purge('mysql');
$expected = str_replace('\\', '/', dirname(__DIR__)).'/storage/local-mysql';
$actual = str_replace('\\', '/', DB::selectOne('SELECT @@datadir AS path')->path);
if (strcasecmp(rtrim($actual, '/'), $expected) !== 0 || DB::getDatabaseName() !== 'lead_generator_test') {
    throw new RuntimeException('Refusing to test against an unexpected database.');
}
Artisan::call('migrate', ['--force' => true]);
DB::beginTransaction();
$checks = 0;
$cookies = [];
function expectLocal(bool $value, string $description): void {
    global $checks;
    if (!$value) throw new RuntimeException('FAIL: '.$description);
    $checks++;
    echo 'PASS: '.$description.PHP_EOL;
}
function visitLocal(string $method, string $path, array $data = [], array $headers = [], bool $json = false) {
    global $kernel, $cookies;
    $server = ['HTTP_HOST' => '127.0.0.1:8079', 'SERVER_NAME' => '127.0.0.1', 'SERVER_PORT' => 8079,
        'REMOTE_ADDR' => '127.0.0.249', 'HTTP_ACCEPT' => $json ? 'application/json' : 'text/html'];
    if ($json) $server['CONTENT_TYPE'] = 'application/json';
    foreach ($headers as $name => $value) $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    $request = Request::create('http://127.0.0.1:8079'.$path, $method, $json ? [] : $data, $cookies, [], $server,
        $json ? json_encode($data, JSON_THROW_ON_ERROR) : null);
    $response = $kernel->handle($request);
    foreach ($response->headers->getCookies() as $cookie) $cookies[$cookie->getName()] = $cookie->getValue();
    $kernel->terminate($request, $response);
    return $response;
}
function csrfLocal(string $html): string {
    if (!preg_match('/name="_token" value="([^"]+)"/', $html, $match)) throw new RuntimeException('CSRF token not rendered.');
    return html_entity_decode($match[1]);
}
$failed = false;
try {
    expectLocal(visitLocal('GET', '/up')->getStatusCode() === 200, 'Health route boots');
    $response = visitLocal('GET', '/');
    expectLocal($response->getStatusCode() === 302 && str_ends_with($response->headers->get('Location'), '/login'), 'Anonymous dashboard redirects to login');
    expectLocal(visitLocal('GET', '/settings/connections')->getStatusCode() === 302, 'Connection settings require operator authentication');
    $response = visitLocal('GET', '/login');
    expectLocal($response->getStatusCode() === 200 && str_contains($response->getContent(), 'Welcome back.'), 'Blade login renders');
    expectLocal(str_contains($response->headers->get('Cache-Control'), 'no-store'), 'Private responses disable caching');
    $csrf = csrfLocal($response->getContent());
    $response = visitLocal('POST', '/login', ['email' => 'operator@example.test', 'password' => 'wrong', '_token' => $csrf]);
    expectLocal($response->getStatusCode() === 302 && !session()->has('operator'), 'Incorrect password does not authenticate');
    $response = visitLocal('POST', '/login', ['email' => 'operator@example.test', 'password' => 'Ephemeral-test-password-only']);
    expectLocal($response->getStatusCode() === 419, 'Login requires CSRF token');
    $response = visitLocal('POST', '/login', ['email' => 'operator@example.test', 'password' => 'Ephemeral-test-password-only', '_token' => $csrf]);
    expectLocal($response->getStatusCode() === 302 && session()->has('operator'), 'Configured operator can authenticate');
    $response = visitLocal('GET', '/');
    expectLocal($response->getStatusCode() === 200 && str_contains($response->getContent(), 'Lead inbox'), 'Authenticated dashboard renders against MariaDB');
    $csrf = csrfLocal($response->getContent());
    $settingsPage = visitLocal('GET', '/settings/connections');
    expectLocal(visitLocal('GET', '/setup/operator')->getStatusCode() === 404, 'First-owner setup is unavailable when an operator is configured');
    $originalEnvironmentPath = $app->environmentPath();
    $originalOperator = ['leadgen.admin_email' => config('leadgen.admin_email'), 'leadgen.admin_password_hash' => config('leadgen.admin_password_hash')];
    $setupFixture = storage_path('local-runtime/owner-setup-test-'.bin2hex(random_bytes(6)));
    mkdir($setupFixture, 0700, true);
    file_put_contents($setupFixture.'/.env', "LEAD_ADMIN_EMAIL=\nLEAD_ADMIN_PASSWORD_HASH=''\n");
    try {
        $app->useEnvironmentPath($setupFixture);
        config(['leadgen.admin_email' => '', 'leadgen.admin_password_hash' => '']);
        expectLocal(visitLocal('GET', '/setup/operator')->getStatusCode() === 200, 'Empty local installation exposes the first-owner setup form');
        $remoteSetup = Request::create('http://127.0.0.1:8079/setup/operator', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.7']);
        expectLocal($kernel->handle($remoteSetup)->getStatusCode() === 404, 'First-owner setup refuses non-loopback clients');
        $setupData = ['email' => 'bootstrap@example.test', 'password' => 'Only-an-ephemeral-test-password', 'password_confirmation' => 'Only-an-ephemeral-test-password'];
        expectLocal(visitLocal('POST', '/setup/operator', $setupData)->getStatusCode() === 419, 'First-owner creation requires CSRF');
        $createdOwner = visitLocal('POST', '/setup/operator', $setupData + ['_token' => $csrf]);
        $setupEnvironment = file_get_contents($setupFixture.'/.env');
        $parsedSetup = \Dotenv\Dotenv::parse($setupEnvironment);
        expectLocal($createdOwner->getStatusCode() === 302 && password_verify($setupData['password'], $parsedSetup['LEAD_ADMIN_PASSWORD_HASH'])
            && !str_contains($setupEnvironment, $setupData['password']), 'First-owner creation stores only a password hash in the isolated environment fixture');
        expectLocal(visitLocal('POST', '/setup/operator', $setupData + ['_token' => $csrf])->getStatusCode() === 404, 'On-disk owner guard blocks a second setup before configuration refresh');
    } finally {
        $app->useEnvironmentPath($originalEnvironmentPath);
        config($originalOperator);
        unlink($setupFixture.'/.env'); rmdir($setupFixture);
    }
    expectLocal($settingsPage->getStatusCode() === 200 && str_contains($settingsPage->getContent(), 'Save connection details'), 'Operator can open the persistent Meta account-details form');
    $settings = ['facebook_page_url' => 'https://www.facebook.com/test-business/', 'facebook_page_id' => '123456789', 'instagram_username' => '@test_business',
        'instagram_account_id' => '178900000', 'meta_app_id' => '456789000', 'webhook_url' => 'https://hooks.example.com/webhook/meta-test', 'n8n_credential_name' => 'Meta test label'];
    expectLocal(visitLocal('PUT', '/settings/connections', $settings)->getStatusCode() === 419, 'Saving connection details requires CSRF');
    $saved = visitLocal('PUT', '/settings/connections', $settings + ['_token' => $csrf, 'enable_private_collection' => true]);
    $storedSettings = \App\Models\IntegrationSetting::findOrFail('meta')->data;
    expectLocal($saved->getStatusCode() === 302 && $storedSettings['instagram_username'] === 'test_business' && $storedSettings['facebook_page_id'] === '123456789', 'Meta identifiers persist and the Instagram at-sign is normalized');
    expectLocal(!array_key_exists('enable_private_collection', $storedSettings) && !config('leadgen.sources.facebook-inbox.permitted', false), 'Saving identifiers cannot activate private intake or change source approval');
    $badSettings = array_replace($settings, ['facebook_page_url' => 'https://facebook.com.evil.test/page']);
    visitLocal('PUT', '/settings/connections', $badSettings + ['_token' => $csrf]);
    expectLocal(\App\Models\IntegrationSetting::findOrFail('meta')->data === $storedSettings, 'Off-platform Facebook links are rejected without overwriting saved details');
    visitLocal('PUT', '/settings/connections', array_replace($settings, ['webhook_url' => 'https://127.0.0.1/webhook']) + ['_token' => $csrf]);
    expectLocal(\App\Models\IntegrationSetting::findOrFail('meta')->data === $storedSettings, 'A loopback HTTPS URL cannot be saved as a public Meta webhook');
    visitLocal('PUT', '/settings/connections', $settings + ['_token' => $csrf, 'access_token' => 'non-secret-test-marker']);
    expectLocal(!array_key_exists('access_token', \App\Models\IntegrationSetting::findOrFail('meta')->data) && !session()->has('_old_input.access_token'), 'Accidentally submitted credentials are neither stored nor flashed back');
    expectLocal(str_contains(visitLocal('GET', '/settings/connections')->getContent(), 'test_business'), 'Saved settings survive a fresh page request');
    $lead = ['source' => 'test-board', 'source_id' => 'integration-1', 'url' => 'https://listings.example.test/projects/1',
        'title' => 'Laravel website <script>alert(1)</script>', 'description' => 'Stripe checkout with n8n automation.',
        'budget_min' => 100, 'budget_max' => 200, 'currency' => 'USD'];
    expectLocal(visitLocal('POST', '/api/leads/import', $lead, [], true)->getStatusCode() === 401, 'Import rejects missing token');
    expectLocal(visitLocal('POST', '/api/leads/import', $lead, ['Authorization' => 'Bearer wrong'], true)->getStatusCode() === 401, 'Import rejects incorrect token');
    $auth = ['Authorization' => 'Bearer '.config('leadgen.import_token')];
    expectLocal(visitLocal('POST', '/api/leads/import', array_replace($lead, ['source' => 'unapproved']), $auth, true)->getStatusCode() === 422, 'Import enforces source permission');
    expectLocal(visitLocal('POST', '/api/leads/import', array_replace($lead, ['url' => 'https://elsewhere.example.test/project']), $auth, true)->getStatusCode() === 422, 'Import enforces listing host');
    expectLocal(visitLocal('POST', '/api/leads/import', array_replace($lead, ['url' => 'https://name:password@listings.example.test/project']), $auth, true)->getStatusCode() === 422, 'Import rejects credentials in URL');
    expectLocal(visitLocal('POST', '/api/leads/import', array_replace($lead, ['budget_max' => 50]), $auth, true)->getStatusCode() === 422, 'Import rejects reversed budget range');
    expectLocal(visitLocal('POST', '/api/leads/import', array_replace($lead, ['currency' => null]), $auth, true)->getStatusCode() === 422, 'Import requires currency for budget');
    $response = visitLocal('POST', '/api/leads/import', $lead + ['status' => 'won', 'proposal' => 'Injected', 'ai_score' => 100], $auth, true);
    expectLocal($response->getStatusCode() === 201, 'Permitted test lead imports into MariaDB');
    $id = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)['id'];
    $stored = Lead::findOrFail($id);
    expectLocal($stored->status === 'new' && $stored->proposal === null && $stored->ai_score === null, 'Import cannot assign review or AI fields');
    $response = visitLocal('POST', '/api/leads/import', $lead, $auth, true);
    expectLocal($response->getStatusCode() === 200 && json_decode($response->getContent(), true)['duplicate'] === true, 'Duplicate source import is idempotent');
    $response = visitLocal('POST', '/api/leads/import', array_replace($lead, ['source_id' => 'another-id', 'url' => $lead['url'].'?utm_source=test#fragment']), $auth, true);
    expectLocal($response->getStatusCode() === 200 && Lead::count() === 1, 'Normalized URL prevents duplicate lead');
    expectLocal(visitLocal('GET', '/api/collection/sources', [], [], true)->getStatusCode() === 401, 'Collection status requires import authentication');
    config(['leadgen.sources.disabled-board' => ['permitted' => false, 'kind' => 'remotive', 'hosts' => ['listings.example.test']]]);
    $disabled = visitLocal('POST', '/api/collection/disabled-board/claim', [], $auth, true);
    expectLocal($disabled->getStatusCode() === 200 && !$disabled->getData(true)['allowed'], 'Disabled source cannot obtain a fetch permit');
    $claim = visitLocal('POST', '/api/collection/test-board/claim', [], $auth, true)->getData(true);
    expectLocal($claim['allowed'] === true && isset($claim['run_id']), 'Approved source obtains a persistent collection permit');
    $repeat = visitLocal('POST', '/api/collection/test-board/claim', [], $auth, true)->getData(true);
    expectLocal($repeat['allowed'] === false, 'Immediate manual retries respect the shared source cooldown');
    $batch = ['leads' => [$lead, array_replace($lead, ['source_id' => 'batch-new', 'url' => 'https://listings.example.test/projects/batch']), array_replace($lead, ['source' => 'remotive'])], 'fetched' => 12];
    $completePath = '/api/collection/runs/'.$claim['run_id'].'/complete';
    $complete = visitLocal('POST', $completePath, $batch, $auth, true);
    $summary = $complete->getData(true);
    expectLocal($complete->getStatusCode() === 200 && $summary['imported'] === 1 && $summary['duplicates'] === 1 && $summary['rejected'] === 1, 'Batch import counts added, duplicate and wrong-source items separately');
    $again = visitLocal('POST', $completePath, $batch, $auth, true);
    expectLocal($again->getStatusCode() === 200 && Lead::count() === 2, 'Completing the same collection run again is idempotent');
    config(['leadgen.sources.empty-board' => ['permitted' => true, 'kind' => 'remotive', 'fetch_url' => 'https://listings.example.test/feed', 'hosts' => ['listings.example.test']]]);
    $emptyClaim = visitLocal('POST', '/api/collection/empty-board/claim', [], $auth, true)->getData(true);
    $empty = visitLocal('POST', '/api/collection/runs/'.$emptyClaim['run_id'].'/complete', ['leads' => [], 'fetched' => 10], $auth, true);
    expectLocal($empty->getStatusCode() === 200 && $empty->getData(true)['status'] === 'completed', 'A valid source with zero relevant leads is a successful empty run');
    config(['leadgen.sources.rate-board' => ['permitted' => true, 'kind' => 'remotive', 'fetch_url' => 'https://listings.example.test/feed', 'hosts' => ['listings.example.test']]]);
    foreach ([7,8,9,10] as $hours) \App\Models\CollectionRun::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'source' => 'rate-board', 'created_at' => now()->subHours($hours)]);
    $limited = visitLocal('POST', '/api/collection/rate-board/claim', [], $auth, true)->getData(true);
    expectLocal($limited['allowed'] === false, 'Four provider attempts in a rolling 24 hours exhaust the persistent budget');
    $response = visitLocal('GET', '/');
    expectLocal($response->getStatusCode() === 200 && !str_contains($response->getContent(), '<script>alert(1)</script>') && str_contains($response->getContent(), '&lt;script&gt;'), 'Dashboard escapes imported markup');
    expectLocal(str_contains($response->getContent(), 'Recent collection runs'), 'Dashboard renders collection history with the lead inbox');
    $excluded = Lead::where('source_id', 'batch-new')->firstOrFail();
    $excluded->status = 'rejected'; $excluded->save();
    $activeInbox = visitLocal('GET', '/')->getContent();
    $rejectedInbox = visitLocal('GET', '/?status=rejected')->getContent();
    expectLocal(!str_contains($activeInbox, '/leads/'.$excluded->id.'"') && str_contains($rejectedInbox, '/leads/'.$excluded->id.'"'), 'Rejected candidates remain reviewable but do not pollute the default active inbox');
    $response = visitLocal('GET', '/?q=missing-query');
    expectLocal($response->getStatusCode() === 200 && str_contains($response->getContent(), 'No matching leads'), 'Dashboard search handles empty results');
    $response = visitLocal('PATCH', '/leads/'.$id, ['status' => 'shortlisted', 'proposal' => '<script>proposal</script>']);
    expectLocal($response->getStatusCode() === 419, 'Lead updates require CSRF token');
    $response = visitLocal('PATCH', '/leads/'.$id, ['status' => 'shortlisted', 'proposal' => '<script>proposal</script>', '_token' => $csrf]);
    expectLocal($response->getStatusCode() === 302 && Lead::findOrFail($id)->status === 'shortlisted', 'Lead status and draft save persist');
    $overview=app(\App\Http\Controllers\DashboardController::class)->index(Request::create('/'))->getData()['overview'];
    expectLocal($overview['new_leads']===2 && $overview['all_records']===2 && count($overview['days'])===7, 'Overview charts include the seven-day captured cohort, including rejected candidates');
    expectLocal($overview['qualification_rate']===100.0 && $overview['win_rate']===0.0, 'Qualification uses active records while conversion uses all captured records');
    expectLocal($overview['reply_rate']===null && $overview['approval_rate']===null && count(array_filter($overview['days'],fn($d)=>$d['rate']===null))===6, 'Missing message denominators and days without leads remain unavailable rather than fabricated percentages');
    $response = visitLocal('GET', '/leads/'.$id);
    expectLocal($response->getStatusCode() === 200 && str_contains($response->getContent(), '&lt;script&gt;proposal&lt;/script&gt;'), 'Lead view escapes saved proposal');
    expectLocal(Http::recorded()->isEmpty(), 'No AI/provider HTTP requests sent');
    config(['leadgen.sources.jobicy' => array_replace($jobicySource, ['permitted' => true])]);
    \Illuminate\Support\Facades\Http::fake(['https://jobicy.com/api/jobs/status*' =>
        \Illuminate\Support\Facades\Http::response(['jobs' => [['id' => 987654, 'status' => 'closed']]])]);
    $jobicyClaim = visitLocal('POST', '/api/collection/jobicy/claim', [], $auth, true)->getData(true);
    $jobicy = ['source' => 'jobicy', 'source_id' => '987654', 'url' => 'https://jobicy.com/jobs/987654',
        'title' => 'Contract Laravel developer', 'description' => 'Build a Laravel application with Stripe.'];
    $result = visitLocal('POST', '/api/collection/runs/'.$jobicyClaim['run_id'].'/complete', ['leads' => [$jobicy], 'fetched' => 1], $auth, true)->getData(true);
    $closedJob = Lead::where('source', 'jobicy')->where('source_id', '987654')->firstOrFail();
    expectLocal($result['imported'] === 1 && !Lead::openListings()->whereKey($closedJob->id)->exists(), 'Jobicy closure removes a saved job from active listings');
    expectLocal(app(\App\Services\OutboxService::class)->closed($closedJob) && !str_contains(visitLocal('GET', '/')->getContent(), $jobicy['url']), 'Platform applications use the original listing and closed roles stay out of the active inbox');
    visitLocal('POST', '/logout', ['_token' => $csrf]);
    expectLocal(visitLocal('GET', '/')->getStatusCode() === 302, 'Logout revokes dashboard access');
    echo "\n$checks Laravel/MariaDB checks passed. Test records are rolled back; live local inbox is unchanged.\n";
} catch (Throwable $e) {
    $failed = true;
    fwrite(STDERR, $e->getMessage().PHP_EOL);
} finally {
    DB::rollBack();
}
exit($failed ? 1 : 0);

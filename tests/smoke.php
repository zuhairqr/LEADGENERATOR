<?php
/** Dependency-free smoke checks. These do NOT replace Laravel/MySQL integration tests. */
require __DIR__.'/../app/Services/LeadMatcher.php';

use App\Services\LeadMatcher;

$count = 0;
function check(bool $condition, string $message): void {
    global $count;
    if (!$condition) throw new RuntimeException('FAIL: '.$message);
    $count++;
    echo 'PASS: '.$message.PHP_EOL;
}
$matcher = new LeadMatcher;
$result = $matcher->score('Laravel website', 'Need n8n workflow automation, OpenAI chatbot and Stripe checkout.');
check(count($result['matched_services']) === 5, 'All five service categories are recognized');
check($result['match_score'] === 100, 'Score is capped at 100');
check($matcher->score('Gardening work', 'Lawn mowing')['match_score'] === 0, 'Unrelated projects do not match');
check($matcher->score('Fragile sculpture', 'We have a ragged edge')['match_score'] === 0, 'RAG is not matched inside unrelated words');
check($matcher->score('LARAVEL developer', 'Build an API')['matched_services'] === ['Laravel'], 'Matching is case-insensitive');
check($matcher->score('Stripe Stripe Stripe', '')['match_score'] === 52, 'Repeated keywords do not inflate score');
check($matcher->score('Laravel API', 'No listed budget')['match_score'] === 52, 'Single category score is explainable');
$a = $matcher->fingerprint('https://JOBS.example.org/p/42?b=2&utm_source=test&a=1#section');
$b = $matcher->fingerprint('http://jobs.example.org/p/42?a=1&b=2');
check($a === $b, 'Tracking, fragments, host case and query order do not create duplicates');
check($matcher->fingerprint('https://jobs.example.org/p?id=1') !== $matcher->fingerprint('https://jobs.example.org/p?id=2'), 'Meaningful query parameters preserve separate listings');
check($matcher->fingerprint('https://jobs.example.org/p?job.id=1') !== $matcher->fingerprint('https://jobs.example.org/p?job_id=1'), 'Query key punctuation is preserved');
check($matcher->fingerprint('https://jobs.example.org:443/p') === $matcher->fingerprint('https://jobs.example.org/p'), 'Default HTTPS port canonicalizes');
foreach (['javascript:alert(1)', 'file:///etc/passwd', 'https://user:password@example.org/p'] as $url) {
    $rejected = false;
    try { $matcher->fingerprint($url); } catch (InvalidArgumentException $e) { $rejected = true; }
    check($rejected, 'Unsafe or credential-bearing URL rejected');
}
$root = dirname(__DIR__);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$parsed = 0;
foreach ($files as $file) {
    $path = $file->getPathname();
    if (str_contains($path, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) continue;
    if ($file->getExtension() === 'php' && !str_ends_with($path, '.blade.php')) {
        token_get_all(file_get_contents($path), TOKEN_PARSE);
        $parsed++;
    }
}
check($parsed >= 20, 'PHP source parses successfully ('.$parsed.' files; Blade compilation not included)');
$composer = json_decode(file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
check(isset($composer['require']['laravel/framework']), 'Composer manifest specifies Laravel');
$workflow = json_decode(file_get_contents($root.'/n8n/lead-import.template.json'), true, 512, JSON_THROW_ON_ERROR);
check($workflow['active'] === false, 'n8n template is inactive by default');
$names = array_column($workflow['nodes'], 'name');
foreach ($workflow['connections'] as $source => $outputs) {
    check(in_array($source, $names, true), 'n8n source node exists');
    foreach ($outputs['main'] as $connections) foreach ($connections as $target) {
        check(in_array($target['node'], $names, true), 'n8n target node exists');
    }
}
echo PHP_EOL.$count.' checks passed. PHP '.PHP_VERSION.PHP_EOL;
echo 'Not exercised: Laravel boot, Blade rendering, auth, MySQL migrations/import concurrency, OpenAI calls, live n8n execution, hosting.'.PHP_EOL;

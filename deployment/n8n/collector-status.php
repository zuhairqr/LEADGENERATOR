<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo json_encode(['sources' => App\Models\CollectionRun::orderBy('created_at')->get(['id','source','status','fetched','matched','imported','duplicates','rejected','error_code','created_at'])->toArray(),
    'leads' => App\Models\Lead::selectRaw('source, status, COUNT(*) as count')->groupBy('source', 'status')->get()->toArray(),
    'active_candidates' => App\Models\Lead::where('status', '!=', 'rejected')->count(),
    'rejected_candidates' => App\Models\Lead::where('status', 'rejected')->count(),
    'operator_configured' => (string) config('leadgen.admin_email') !== '' && (string) config('leadgen.admin_password_hash') !== ''], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;

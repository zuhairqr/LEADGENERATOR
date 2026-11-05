<?php
// Safe operational counters only: no addresses, message bodies or credentials.
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$profile=app(App\Services\ProspectingProfile::class);$bridges=$profile->bridges();$sender=$profile->sender();
$result=['ai_ready'=>$profile->aiReady(),'worker_published'=>$bridges['worker_ready'] ?? false,'ai_workflow_published'=>$bridges['ai_ready'] ?? false,
 'gmail_published'=>$bridges['gmail_ready'] ?? false,'inbox_published'=>$bridges['inbox_ready'] ?? false,
 'business_facts_confirmed'=>(bool)($profile->data()['profile_verified'] ?? false),'auto_draft_enabled'=>(bool)($profile->data()['auto_draft'] ?? false),
 'sender_configured'=>!empty($sender['from_email']) && !empty($sender['sender_verified']),
 'smtp_health'=>App\Models\IntegrationSetting::find('smtp_health')?->data,
 'messages'=>App\Models\OutboundMessage::selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count','status')->toArray(),
 'runtime'=>App\Models\IntegrationSetting::find('sales_runtime')?->data];
echo json_encode($result,JSON_PRETTY_PRINT).PHP_EOL;

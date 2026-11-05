<?php
// Read-only local status: no mailbox addresses, messages or credentials printed.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('local')||config('database.connections.mysql.host')!=='127.0.0.1'||config('database.connections.mysql.port')!=3310||config('database.connections.mysql.database')!=='lead_generator_local')throw new RuntimeException('Dedicated local environment required.');
$profile=app(App\Services\ProspectingProfile::class);
$sender=$profile->sender();$bridges=$profile->bridges();
$health=App\Models\IntegrationSetting::find('mailbox_health')?->data??[];
$sync=App\Models\IntegrationSetting::find('mailbox_sync')?->data??[];
echo json_encode(['gmail_provider'=>($sender['provider']??'')==='gmail','sender_verified'=>!empty($sender['sender_verified']),
 'account_connected'=>!empty($health['connected']),'read_access'=>!empty($health['read_access']),'sender_matches'=>!empty($health['sender_matches']),
 'gmail_ready'=>!empty($bridges['gmail_ready']),'inbox_ready'=>!empty($bridges['inbox_ready']),
 'sync_ok'=>!empty($sync['ok']),'sync_checked_at'=>$sync['checked_at']??null,'tracked_contacts'=>$sync['tracked_contacts']??0,'matched_replies'=>$sync['matched']??0,
 'approved_queue'=>App\Models\OutboundMessage::where('status','approved')->count(),'sent_messages'=>App\Models\OutboundMessage::where('status','sent')->count()],JSON_UNESCAPED_SLASHES).PHP_EOL;

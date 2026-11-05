<?php
// Safe local readiness probe. No provider credentials, contact addresses or messages are printed.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('local'))throw new RuntimeException('Local workspace only');
$profile=app(App\Services\ProspectingProfile::class);
$result=['checked_at'=>gmdate('c'),'business_candidates'=>App\Models\Lead::where('kind','business')->count(),
 'business_country_holds'=>App\Models\BusinessRecord::where('geo_status','unknown')->count(),
 'audit_states'=>App\Models\WebsiteAudit::selectRaw('status,COUNT(*) AS count')->groupBy('status')->pluck('count','status')->all(),
 'outbox_states'=>App\Models\OutboundMessage::selectRaw('status,COUNT(*) AS count')->groupBy('status')->pluck('count','status')->all(),
 'channel_states'=>App\Models\ChannelMessage::selectRaw('status,COUNT(*) AS count')->groupBy('status')->pluck('count','status')->all(),
 'configured_gateways'=>App\Models\ConnectorConfig::count(),
 'targeting'=>['worldwide'=>empty($profile->data()['locations']),'service_groups'=>count($profile->skills()),'excluded_countries'=>app(App\Services\CountryPolicy::class)->excluded()],
 'discovery_runtime'=>App\Models\IntegrationSetting::find('discovery_runtime')?->data,
 'sales_runtime'=>App\Models\IntegrationSetting::find('sales_runtime')?->data];
if(in_array('--verify-gmail',$argv,true))$result['gmail_account_read_probe']=app(App\Services\MailboxSync::class)->verify();
$bridges=$profile->bridges();$result['readiness']=array_intersect_key($bridges,array_flip(['ai_ready','worker_ready','discovery_ready','gmail_installed','inbox_installed','gmail_ready','inbox_ready']));
file_put_contents(storage_path('n8n/logs/expanded-local-health.json'),json_encode($result,JSON_PRETTY_PRINT));echo json_encode($result,JSON_PRETTY_PRINT).PHP_EOL;

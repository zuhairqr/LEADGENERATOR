<?php
// One normal, rate-limited preview search; no import, AI call or outreach.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!in_array('--search',$argv,true)||!app()->environment('local')||config('database.connections.mysql.port')!=3310||config('database.connections.mysql.database')!=='lead_generator_local')throw new RuntimeException('Explicit local search probe required.');
try{
 $result=app(App\Services\BusinessDiscovery::class)->search('accounting firms','',false,'business');
 $safe=['quality'=>$result['quality'],'samples'=>array_map(fn($r)=>['name'=>$r['name'],'website'=>$r['website'],'relevance'=>$r['_discovery_evidence']['score'],'buyer_request'=>$r['_discovery_evidence']['buyer_request']],array_slice($result['items'],0,5)),'records_imported'=>0,'emails_sent'=>0];
 file_put_contents(storage_path('n8n/logs/business-quality-probe.json'),json_encode($safe,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
 echo json_encode($safe,JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){$code=preg_match('/^[A-Z_]{3,80}$/',$e->getMessage())?$e->getMessage():'LOCAL_PROBE_FAILED';echo json_encode(['error_code'=>$code,'emails_sent'=>0]).PHP_EOL;exit(1);}

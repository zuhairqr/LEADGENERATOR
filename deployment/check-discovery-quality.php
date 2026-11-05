<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('local')||config('database.connections.mysql.port')!=3310||config('database.connections.mysql.database')!=='lead_generator_local')throw new RuntimeException('Dedicated local database required.');
$runs=App\Models\CollectionRun::where('source','business-web')->latest()->limit(4)->get();
$activePlatforms=App\Models\Lead::where('source','business-web')->where('kind','business')->where('status','!=','rejected')->with('business')->get()->filter(fn($l)=>App\Services\BusinessProspectQuality::blockedHost($l->business?->domain??''))->count();
echo json_encode(['active_platform_prospects'=>$activePlatforms,'recent_runs'=>$runs->map(fn($r)=>['created_at'=>$r->created_at->toIso8601String(),'status'=>$r->status,'fetched'=>$r->fetched,'matched'=>$r->matched,'rejected'=>$r->rejected,'quality'=>$r->quality_summary])->all(),'mail_test_prepared'=>App\Models\Lead::where('kind',App\Services\MailConnectionTest::KIND)->exists(),'sent_messages'=>App\Models\OutboundMessage::where('status','sent')->count()],JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;

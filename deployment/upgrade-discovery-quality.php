<?php
// Additive local upgrade; reclassifies only untouched, automatically discovered records.
require __DIR__.'/../vendor/autoload.php';
use App\Models\{Lead,OutboundMessage,OutreachEvent};
use App\Services\{BusinessProspectQuality,ProspectingProfile};
use Illuminate\Support\Facades\{Artisan,DB};
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('local')||config('database.connections.mysql.host')!=='127.0.0.1'||config('database.connections.mysql.port')!=3310||config('database.connections.mysql.database')!=='lead_generator_local')throw new RuntimeException('Dedicated local database required.');
$actual=str_replace('\\','/',DB::selectOne('SELECT @@datadir AS path')->path);
if(strcasecmp(rtrim($actual,'/'),str_replace('\\','/',dirname(__DIR__)).'/storage/local-mysql'))throw new RuntimeException('Unexpected database directory.');
if(!in_array('--apply',$argv,true))throw new RuntimeException('Explicit --apply is required.');
Artisan::call('migrate',['--force'=>true,'--path'=>'database/migrations/2026_11_05_000008_business_discovery_quality.php']);
$quality=app(BusinessProspectQuality::class);$skills=app(ProspectingProfile::class)->skills();
$changed=$rejected=0;$snapshot=[];
DB::transaction(function()use($quality,$skills,&$changed,&$rejected,&$snapshot){
 $snapshot=[];$changed=$rejected=0;
 $leads=Lead::where('source','business-web')->where('kind','business')->where('status','new')->whereNull('proposal')->whereNull('contact_email')->whereNull('contact_permission_at')->whereNull('country_reviewed_at')->lockForUpdate()->get();
 foreach($leads as $lead){
  if(!$lead->business||$lead->notes()->exists()||$lead->tasks()->exists()||OutboundMessage::where('lead_id',$lead->id)->exists())continue;
  $blocked=BusinessProspectQuality::blockedHost($lead->business->domain??'');
  $audit=$lead->audits()->where('status','completed')->latest()->first();
  if(!$blocked&&!$audit)continue;
  $snapshot[]=['lead'=>$lead->getAttributes(),'business_id'=>$lead->business->id,'discovery_evidence'=>$lead->business->discovery_evidence];
 }
 if($snapshot){$file=storage_path('local-runtime/discovery-quality-backup-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(3)).'.json');if(file_put_contents($file,json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT))===false)throw new RuntimeException('Backup failed.');@chmod($file,0600);}
 foreach($snapshot as $old){
  $lead=Lead::findOrFail($old['lead']['id']);$blocked=BusinessProspectQuality::blockedHost($lead->business->domain??'');
  if($blocked){$lead->status='rejected';$lead->match_score=0;$lead->matched_services=[];$lead->match_reason='Search quality review: platform/content host, not a direct business prospect. Original record and provenance retained.';$rejected++;
   $lead->business->update(['discovery_evidence'=>['policy_version'=>1,'score'=>0,'signals'=>[],'buyer_request'=>false,'service_matches'=>[],'reason'=>'PLATFORM_DIRECTORY_OR_CONTENT_HOST','checked_at'=>now()->toIso8601String()]]);
  }else{$audit=$lead->audits()->where('status','completed')->latest()->first();$fit=$quality->auditFit($audit->findings??[],$skills,$lead->business->discovery_evidence??[]);$lead->match_score=$fit['score'];$lead->matched_services=$fit['services'];$lead->match_reason=$fit['reason'];}
  $lead->save();OutreachEvent::create(['lead_id'=>$lead->id,'event'=>'discovery_quality_reviewed','details'=>['policy_version'=>1,'platform_filtered'=>$blocked]]);$changed++;
 }
},3);
echo json_encode(['migration_applied'=>true,'reviewed_records'=>$changed,'platform_records_rejected'=>$rejected,'backed_up_records'=>count($snapshot),'records_deleted'=>0,'emails_sent'=>0]).PHP_EOL;

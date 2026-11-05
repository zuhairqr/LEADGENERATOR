<?php
namespace App\Services;
use App\Models\{Lead,IntegrationSetting};
use Illuminate\Support\Facades\Cache;
class DiscoveryPipeline {
 public function tick():array {
  $lock=Cache::lock('discovery-pipeline',150);if(!$lock->get())return ['status'=>'busy'];$result=['search'=>'idle','audit'=>'idle','connector'=>'checked'];
  try{
   try{app(BusinessDiscovery::class)->scheduled();$result['search']='checked';}catch(\Throwable $e){$result['search']='failed';}
   try{app(ConnectorService::class)->scheduled();}catch(\Throwable $e){$result['connector']='failed';}
   $settings=app(BusinessDiscovery::class)->settings();if($settings['auto_audit']??false){$lead=Lead::where('kind','business')->whereIn('status',['new','shortlisted'])->whereHas('business',fn($q)=>$q->whereNotNull('website')->where('geo_status','!=','excluded'))->whereDoesntHave('audits',fn($q)=>$q->where('created_at','>',now()->subDay()))->oldest()->first();if($lead)try{$audit=app(WebsiteAuditService::class)->run($lead);$result['audit']=$audit->status;}catch(\Throwable $e){$result['audit']='failed';}}
   IntegrationSetting::updateOrCreate(['key'=>'discovery_runtime'],['data'=>$result+['checked_at'=>now()->toIso8601String()]]);return $result;
  }finally{$lock->release();}
 }
}

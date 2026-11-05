<?php
namespace App\Services;
use App\Models\{IntegrationSetting,CollectionRun};
use Illuminate\Support\Facades\{Http,Cache};
use Illuminate\Support\Str;
class BusinessDiscovery {
 public function settings():array{return IntegrationSetting::find('business_discovery')?->data??['niches'=>['service businesses'],'locations'=>[],'enabled'=>false,'auto_audit'=>false];}
 public function search(string $niche,string $location='',bool $store=false,?string $mode=null):array {
  if(!config('leadgen.sources.business-web.permitted'))throw new \RuntimeException('BUSINESS_SOURCE_APPROVAL_REQUIRED');
  $lock=Cache::lock('business-search',60);if(!$lock->get())throw new \RuntimeException('SEARCH_BUSY');
  try{
   $recent=CollectionRun::where('source','business-web')->where('created_at','>',now()->subHours(24));if($recent->count()>=4)throw new \RuntimeException('BUSINESS_SEARCH_DAILY_LIMIT');
   $last=(clone $recent)->latest()->first();if($last && $last->created_at->gt(now()->subMinute()))throw new \RuntimeException('BUSINESS_SEARCH_COOLDOWN');
   $run=CollectionRun::create(['id'=>(string)Str::uuid(),'source'=>'business-web']);
   try{
    $quality=app(BusinessProspectQuality::class);$skills=app(ProspectingProfile::class)->skills();
    $plan=$quality->plan($niche,$location,max(0,CollectionRun::where('source','business-web')->count()-1),$skills,$mode);
    $r=Http::connectTimeout(5)->timeout(30)->get('http://127.0.0.1:8082/search',['q'=>$plan['query'],'format'=>'json','safesearch'=>1]);if(!$r->successful()||!is_array($r->json('results')))throw new \RuntimeException('SEARCH_PROVIDER_FAILED');
    $accepted=[];$filtered=[];$searchDuplicates=0;
    foreach($r->json('results') as $row){
      if(!is_array($row)){$filtered['INVALID_RESULT']=($filtered['INVALID_RESULT']??0)+1;continue;}
      $assessment=$quality->assess($row,$plan['target'],$skills,$plan['mode']);
      if(!$assessment['accepted']){$reason=$assessment['reason'];$filtered[$reason]=($filtered[$reason]??0)+1;continue;}
      $host=$assessment['host'];
      if(isset($accepted[$host])){$searchDuplicates++;if($accepted[$host]['score']>=$assessment['score'])continue;}
      $accepted[$host]=$assessment;
    }
    uasort($accepted,fn($a,$b)=>$b['score']<=>$a['score']);$items=[];
    foreach(array_slice($accepted,0,20) as $a){
      $url=$a['url'];$origin=parse_url($url,PHP_URL_SCHEME).'://'.parse_url($url,PHP_URL_HOST).'/';
      $evidence=array_intersect_key($a,array_flip(['score','signals','buyer_request','service_matches','target','mode']));$evidence+=['policy_version'=>1,'checked_at'=>now()->toIso8601String(),'source_url'=>$url];
      $items[]=['name'=>'Candidate: '.$a['name'],'website'=>$origin,'source_url'=>$url,'source_id'=>hash('sha256',$url),'niche'=>$plan['target'],'location'=>$location,'description'=>mb_substr($a['name'].' — '.$a['snippet'],0,4000),'_discovery_evidence'=>$evidence,'attribution'=>'Public SearXNG result; relevance '.$a['score'].'/100 is a heuristic, not buyer intent. Identity, country and outreach permission need review. Original: '.$url];
    }
    $summary=['policy_version'=>1,'target'=>$plan['target'],'mode'=>$plan['mode'],'saved_service'=>$plan['mode']==='service-request'?$plan['service']:null,'accepted'=>count($items),'filtered'=>array_sum($filtered),'filter_reasons'=>$filtered,'duplicate_domains'=>$searchDuplicates];
    $imported=$duplicates=0;if($store)foreach($items as $item){$result=app(BusinessIntake::class)->import($item,'business-web');$result['duplicate']?$duplicates++:$imported++;}
    $run->update(['status'=>'completed','fetched'=>count($r->json('results')),'matched'=>count($items),'imported'=>$imported,'duplicates'=>$duplicates,'rejected'=>array_sum($filtered),'quality_summary'=>$summary,'finished_at'=>now()]);return ['items'=>$items,'run_id'=>$run->id,'imported'=>$imported,'duplicates'=>$duplicates,'quality'=>$summary];
   }catch(\Throwable $e){$run->update(['status'=>'failed','error_code'=>'BUSINESS_SEARCH_FAILED','finished_at'=>now()]);throw new \RuntimeException('BUSINESS_SEARCH_FAILED');}
  }finally{$lock->release();}
 }
 public function scheduled():void {
  $s=$this->settings();if(empty($s['enabled']))return;$latest=CollectionRun::where('source','business-web')->latest()->first();if($latest && $latest->created_at->gt(now()->subHours(6)))return;
  $count=CollectionRun::where('source','business-web')->count();$n=$s['niches']??['service businesses'];$l=$s['locations']??[];$this->search($n[$count%count($n)],$l?$l[intdiv($count,count($n))%count($l)]:'',true,$s['search_mode']??'service-request');
 }
}

<?php
namespace App\Services;
use App\Models\{Lead,BusinessRecord,SourceProvenance,OutreachEvent};
use Illuminate\Support\Facades\{DB,Validator};
class BusinessIntake {
 public function import(array $row,string $source='manual-business'):array {
  if($source==='business-web'&&!config('leadgen.sources.business-web.permitted'))throw new \RuntimeException('BUSINESS_SOURCE_APPROVAL_REQUIRED');
  $d=Validator::make($row,['name'=>'required|string|max:200','website'=>'nullable|string|max:2048','source_url'=>'nullable|url:http,https|max:2048','niche'=>'nullable|string|max:120','location'=>'nullable|string|max:200','country'=>'nullable|string|max:80','email'=>'nullable|email|max:254','phone'=>'nullable|string|max:80','description'=>'nullable|string|max:8000','source_id'=>'nullable|string|max:190','attribution'=>'nullable|string|max:2000'])->validate();
  $raw=trim($d['website']??'');if($raw&&!str_contains($raw,'://'))$raw='https://'.$raw;
  $website=$raw?PublicWebClient::url($raw):null;$domain=$website?preg_replace('/^www\./','',strtolower(parse_url($website,PHP_URL_HOST))):null;
  if(!$website && empty($d['source_url']))throw new \RuntimeException('WEBSITE_OR_SOURCE_LINK_REQUIRED');
  $key=hash('sha256',$domain?'domain:'.$domain:'name:'.mb_strtolower(preg_replace('/\s+/u',' ',trim($d['name']))).'|'.mb_strtolower(trim($d['location']??'')));
  $discovery=null;
  if($source==='business-web'){
   $target=$row['_discovery_evidence']['target']??($d['niche']??'');$mode=$row['_discovery_evidence']['mode']??'business';
   $assessed=app(BusinessProspectQuality::class)->assess(['url'=>$d['source_url']??$website,'title'=>preg_replace('/^Candidate: /','',$d['name']),'content'=>$d['description']??''],$target,app(ProspectingProfile::class)->skills(),$mode);
   if(!$assessed['accepted'])throw new \RuntimeException('BUSINESS_RELEVANCE_REVIEW_REQUIRED');
   $discovery=array_intersect_key($assessed,array_flip(['score','signals','buyer_request','service_matches','target','mode']));$discovery+=['policy_version'=>1,'checked_at'=>now()->toIso8601String(),'source_url'=>$d['source_url']??$website];
  }
  return DB::transaction(function()use($d,$website,$domain,$key,$source,$discovery){
   $business=BusinessRecord::firstOrCreate(['identity_key'=>$key],['name'=>trim($d['name']),'domain'=>$domain,'website'=>$website,'niche'=>$d['niche']??null,'location'=>$d['location']??null,'phone'=>$d['phone']??null,'contacts'=>[]]);
   $business=BusinessRecord::whereKey($business->id)->lockForUpdate()->firstOrFail();
   if($discovery&&(!$business->discovery_evidence||($discovery['score']??0)>=(int)($business->discovery_evidence['score']??0)))$business->discovery_evidence=$discovery;
   if(!empty($d['country'])){$code=CountryPolicy::normalize($d['country']);if(!$business->country_code||in_array($code,app(CountryPolicy::class)->excluded(),true)){$business->country_code=$code;$business->geo_status=app(CountryPolicy::class)->status($code);$business->country_evidence='Imported declared country: '.$d['country'].'; source: '.(!empty($d['source_url'])?$d['source_url']:$website);}}
   $contacts=$business->contacts??[];if(!empty($d['email'])){$email=mb_strtolower(trim($d['email']));$contacts[$email]=['email'=>$email,'source'=>$d['source_url']??$website,'verified'=>false];}$business->contacts=$contacts;$business->save();
   $lead=Lead::where('business_record_id',$business->id)->first();$duplicate=(bool)$lead;$sourceUrl=!empty($d['source_url'])?$d['source_url']:$website;
   if(!$lead){$lead=new Lead(['source'=>$source,'source_id'=>$key,'url_hash'=>hash('sha256','business:'.$key),'url'=>$sourceUrl,'title'=>$business->name,'description'=>'Business prospect, not a posted buyer request. Niche: '.($business->niche??'Unknown').'; location: '.($business->location??'Unknown').'. '.($d['description']??'Review website evidence before making an offer.'),'match_score'=>0,'matched_services'=>[],'match_reason'=>'Business fit and website audit pending.']);$lead->kind='business';$lead->business_record_id=$business->id;$lead->save();OutreachEvent::create(['lead_id'=>$lead->id,'event'=>'business_discovered']);}
   if($business->geo_status==='excluded'){$lead->status='rejected';$lead->save();}
   SourceProvenance::firstOrCreate(['source'=>$source,'external_id'=>$d['source_id']??hash('sha256',$sourceUrl)],['lead_id'=>$lead->id,'url'=>$sourceUrl,'attribution'=>$d['attribution']??null]);
   return ['lead'=>$lead->fresh(),'duplicate'=>$duplicate];
  },3);
 }
}

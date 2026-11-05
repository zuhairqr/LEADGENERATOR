<?php
namespace App\Http\Controllers;
use App\Models\{Lead,IntegrationSetting,WebsiteAudit,OutreachEvent,CollectionRun};
use App\Services\{BusinessIntake,BusinessDiscovery,WebsiteAuditService,PublicWebClient};
use Illuminate\Http\Request;
use Illuminate\Support\Str;
class BusinessController {
 public function index(BusinessDiscovery $discovery){return view('discovery',['settings'=>$discovery->settings(),'businesses'=>Lead::where('kind','business')->with('business')->latest()->paginate(20),'results'=>session('business_search_results',[]),'sourceApproved'=>config('leadgen.sources.business-web.permitted')]);}
 public function settings(Request $r){$d=$r->validate(['niches'=>'required|string|max:1500','locations'=>'nullable|string|max:1500','excluded_countries'=>'nullable|string|max:500','search_mode'=>'nullable|in:business,service-request']);$list=fn($s)=>array_slice(array_values(array_unique(array_filter(array_map('trim',preg_split('/[,\r\n]+/',$s??''))))),0,20);$n=$list($d['niches']);abort_unless($n,422);$excluded=array_values(array_unique(array_filter(array_map([\App\Services\CountryPolicy::class,'normalize'],$list($d['excluded_countries']??'IN,PK')))));
  $mode=$d['search_mode']??app(BusinessDiscovery::class)->settings()['search_mode']??'service-request';
  IntegrationSetting::updateOrCreate(['key'=>'business_discovery'],['data'=>['niches'=>$n,'locations'=>$list($d['locations']??''),'excluded_countries'=>$excluded,'require_country_review'=>true,'enabled'=>$r->boolean('enabled'),'auto_audit'=>$r->boolean('auto_audit'),'search_mode'=>$mode]]);return back()->with('notice','Business targets saved. Public search is limited to four attempts per rolling 24 hours.');}
 public function tick(\App\Services\DiscoveryPipeline $pipeline){set_time_limit(140);return response()->json($pipeline->tick());}
 public function search(Request $request, BusinessDiscovery $discovery)
 {
     $data = $request->validate(['niche' => 'required|string|max:100', 'location' => 'nullable|string|max:100', 'search_mode' => 'nullable|in:business,service-request']);
     try {
         $result = $discovery->search($data['niche'], $data['location'] ?? '', false, $data['search_mode'] ?? 'business');
     } catch (\Throwable $error) {
         $message = match (self::safeCode($error)) {
             'BUSINESS_SOURCE_APPROVAL_REQUIRED' => 'Ask an administrator to enable online search.',
             'BUSINESS_SEARCH_DAILY_LIMIT' => self::dailyLimitMessage(),
             'BUSINESS_SEARCH_COOLDOWN', 'SEARCH_BUSY' => 'Wait a minute before starting another search.',
             default => 'Online search is temporarily unavailable. Try again or add a business manually.',
         };
         return back()->withInput($data)->withErrors(['search' => $message]);
     }

     $request->session()->put([
         'business_search_results' => $result['items'],
         'business_search_quality' => $result['quality'],
         'business_search_batch' => (string) Str::uuid(),
     ]);

     return redirect()->route('discovery.index')->withFragment('search-results')->withInput($data);
 }

 public function import(Request $request, BusinessIntake $intake)
 {
     $request->validate(['permission' => 'accepted']);
     $fromSearch = $request->has('candidate');
     if ($fromSearch) {
         $request->validate(['candidate' => 'required|integer|between:0,19']);
         $batch = $request->input('search_batch');
         $row = $request->session()->get('business_search_results', [])[$request->integer('candidate')] ?? null;
         if (!$row || !is_string($batch) || $batch !== $request->session()->get('business_search_batch')) {
             return redirect()->route('discovery.index')->withErrors(['candidate' => 'Search results changed. Review the current list before saving a lead.']);
         }
     } else {
         $row = $request->only(['name', 'website', 'source_url', 'niche', 'location', 'country', 'email', 'phone', 'description']);
     }

     try {
         $result = $intake->import($row, $fromSearch ? 'business-web' : 'manual-business');
     } catch (\Throwable $error) {
         if ($fromSearch) {
             return redirect()->route('discovery.index')->withErrors(['candidate' => 'This result could not be saved. Check the source or try a fresh search.']);
         }
         return back()->withInput($row)->withErrors(['business' => 'Enter a business name and a valid website or source link.']);
     }

     return redirect()->route('leads.show', $result['lead'])->with('notice', $result['duplicate']
         ? 'Business updated. The original sources are kept on the lead.'
         : 'Business saved. Check its country and website before preparing an offer.');
 }
 public function csv(Request $r,BusinessIntake $intake){$r->validate(['permission'=>'accepted','file'=>'required|file|max:1024']);$h=fopen($r->file('file')->getRealPath(),'rb');$header=fgetcsv($h,0,',','"','');if(!$header){fclose($h);return back()->withErrors(['file'=>'CSV header missing.']);}$header=array_map(fn($x)=>strtolower(trim(ltrim($x,"\xEF\xBB\xBF"))),$header);if(!in_array('name',$header,true)){fclose($h);return back()->withErrors(['file'=>'CSV needs a name column; website or source_url is required per row.']);}
  $counts=['imported'=>0,'duplicates'=>0,'rejected'=>0];$line=0;while(($row=fgetcsv($h,0,',','"',''))!==false){if(++$line>500){$counts['rejected']++;break;}if(count($row)!==count($header)){$counts['rejected']++;continue;}try{$result=$intake->import(array_intersect_key(array_combine($header,$row),array_flip(['name','website','source_url','niche','location','country','email','phone','description','source_id','attribution'])),'csv-business');$result['duplicate']?$counts['duplicates']++:$counts['imported']++;}catch(\Throwable $e){$counts['rejected']++;}}fclose($h);return back()->with('notice','CSV: '.$counts['imported'].' imported, '.$counts['duplicates'].' merged, '.$counts['rejected'].' rejected. No contacts were approved for outreach.');}
 public function update(Request $r,Lead $lead){abort_unless($lead->kind==='business',422);$d=$r->validate(['name'=>'required|string|max:200','website'=>'nullable|string|max:2048','niche'=>'nullable|string|max:120','location'=>'nullable|string|max:200','phone'=>'nullable|string|max:80']);try{if(!empty($d['website']))$d['website']=PublicWebClient::url($d['website']);}catch(\Throwable $e){return back()->withErrors(['website'=>'Use a public HTTP(S) website URL.']);}
  $domain=empty($d['website'])?null:preg_replace('/^www\./','',parse_url($d['website'],PHP_URL_HOST));$d['domain']=$domain;if($domain)$d['identity_key']=hash('sha256','domain:'.$domain);
   $domainChanged=$lead->business->domain!==$domain;
   if($domainChanged){$d['country_code']=null;$d['geo_status']='unknown';$d['country_evidence']=null;}
   try{$lead->business->update($d);}catch(\Illuminate\Database\UniqueConstraintViolationException $e){return back()->withErrors(['website'=>'Another business already has this domain. Review that existing record.']);}
   if($domainChanged){$lead->country_code=null;$lead->country_reviewed_at=null;\App\Models\ChannelMessage::where('lead_id',$lead->id)->whereIn('status',['draft','approved'])->update(['status'=>'draft','approval_hash'=>null,'approved_at'=>null]);}
  $lead->title=$d['name'];$lead->ai_state='pending';$lead->match_score=0;$lead->save();\App\Models\OutboundMessage::where('lead_id',$lead->id)->whereIn('status',['draft','approved'])->update(['status'=>'draft','approval_hash'=>null,'approved_at'=>null]);return back()->with('notice','Business updated. Re-audit and review message content before approving.');}
 public function country(Request $r,Lead $lead){
  $d=$r->validate(['country'=>'required|string|max:80','country_evidence'=>'required|string|max:2000','country_verified'=>'accepted']);$code=\App\Services\CountryPolicy::normalize($d['country']);if(!$code)return back()->withErrors(['country'=>'Use a valid two-letter country code, for example US, GB, CA or AE.']);
  $status=app(\App\Services\CountryPolicy::class)->status($code);
  if($lead->business){$lead->business->update(['country_code'=>$code,'geo_status'=>$status,'country_evidence'=>'Operator-confirmed: '.$d['country_evidence']]);}
  $lead->country_code=$code;$lead->country_reviewed_at=now();if($status==='excluded')$lead->status='rejected';$lead->save();
  if($status==='excluded'){\App\Models\OutboundMessage::where('lead_id',$lead->id)->whereIn('status',['draft','approved'])->update(['status'=>'cancelled','approval_hash'=>null,'approved_at'=>null]);\App\Models\ChannelMessage::where('lead_id',$lead->id)->whereIn('status',['draft','approved'])->update(['status'=>'cancelled','approval_hash'=>null]);}
  OutreachEvent::create(['lead_id'=>$lead->id,'event'=>'country_reviewed','details'=>['country'=>$code,'evidence'=>$d['country_evidence'],'excluded'=>$status==='excluded']]);return back()->with('notice','Country review saved. Excluded countries cannot receive outreach.');
 }

 public function audit(Lead $lead,WebsiteAuditService $service){set_time_limit(80);try{$audit=$service->run($lead);return back()->with($audit->status==='completed'?'notice':'audit_error',$audit->status==='completed'?'Static website audit completed. Review evidence and limitations.':'Audit failed: '.$audit->error_code);}catch(\Throwable $e){return back()->withErrors(['audit'=>'Audit unavailable: '.self::safeCode($e)]);}}
 public function template(){return response("name,website,source_url,niche,location,country,email,phone,description\r\n",200,['Content-Type'=>'text/csv','Content-Disposition'=>'attachment; filename="business-import-template.csv"']);}
 private static function safeCode(\Throwable $e):string{return preg_match('/^[A-Z_]{3,80}$/',$e->getMessage())?$e->getMessage():'CHECK_SETTINGS';}
 private static function dailyLimitMessage():string{
  $oldest=CollectionRun::where('source','business-web')->where('created_at','>',now()->subHours(24))->oldest()->first();
  $retry=$oldest?->created_at->copy()->addDay()->timezone('Asia/Karachi')->format('d M Y, H:i').' PKT';
  return 'This source allows four search attempts per rolling 24 hours, including searches with no results.'.($retry?' Next search: '.$retry.'.':' Try again after the oldest attempt is 24 hours old.');
 }
}

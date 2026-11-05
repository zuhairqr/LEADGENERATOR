<?php
namespace App\Http\Controllers;
use App\Models\{ConnectorConfig,ConnectorRun,CollectionRun,IntegrationSecret,Lead};
use App\Services\{SourceCatalog,PublicWebClient,ConnectorService,ConnectorFailure};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
class ConnectorController {
 public function index(SourceCatalog $catalog){
  $connectors=ConnectorConfig::latest()->get();
  $selectedSources=app(\App\Services\ProspectingProfile::class)->data()['enabled_sources']??null;
  $builtInSources=[];
  foreach(config('leadgen.sources',[]) as $id=>$source){
   $builtInSources[]=['id'=>$id,'label'=>$source['label']??$id,'kind'=>$source['kind']??'',
    'approved'=>($source['permitted']??false)===true,
    'selected'=>!is_array($selectedSources)||in_array($id,$selectedSources,true),
    'active_leads'=>Lead::prospects()->where('source',$id)->where('status','!=','rejected')->count(),
    'last'=>CollectionRun::where('source',$id)->latest()->first()];
  }
  $latestConnectorRuns=[];
  foreach($connectors as $connector)$latestConnectorRuns[$connector->id]=ConnectorRun::where('connector_id',$connector->id)->latest()->first();
  $secretKeys=$connectors->map(fn($connector)=>'cn_'.str_replace('-','',$connector->id));
  $savedKeys=IntegrationSecret::whereIn('key',$secretKeys)->pluck('key')->flip();
  $bedrock=app(\App\Services\BedrockResearchService::class);
  return view('connectors',['groups'=>$catalog->groups(),'platforms'=>$catalog->platforms(),'connectors'=>$connectors,'builtInSources'=>$builtInSources,'latestConnectorRuns'=>$latestConnectorRuns,'savedKeys'=>$savedKeys,'communityScopes'=>\App\Services\ProspectingProfile::publicCommunityScopes(),'runs'=>ConnectorRun::latest()->limit(30)->get(),'bedrockConfigured'=>$bedrock->configured(),'bedrockSettings'=>$bedrock->settings()]);
 }
 public function save(Request $r,SourceCatalog $catalog,?ConnectorConfig $connector=null){
  $v=Validator::make($r->all(),['name'=>'required|string|max:150','platform'=>['required',Rule::in(array_merge($catalog->platforms(),['Custom approved API / feed','Custom CRM','Custom outreach gateway']))],'purpose'=>['required',Rule::in(['feed','crm','outreach'])],'endpoint'=>'required|string|max:2048','items_path'=>['nullable','string','max:150','regex:/^[A-Za-z0-9_.]*$/'],'mapping'=>'nullable|json|max:8000','method'=>['required',Rule::in(['GET','POST'])],'request_body'=>'nullable|json|max:8000','auth_header'=>['required','string','max:64'],'token'=>['nullable','string','max:2048','not_regex:/[\r\n]/'],'channel'=>'nullable|string|max:50','permission'=>'accepted']);if($v->fails())return back()->withErrors($v);$d=$v->validated();
  if(!ConnectorService::validHeader($d['auth_header']))return back()->withErrors(['auth_header'=>'Use a valid authentication header name.']);
  try{$endpoint=PublicWebClient::url($d['endpoint']);if(parse_url($endpoint,PHP_URL_SCHEME)!=='https')throw new \RuntimeException();if(preg_match('/(?:^|[?&])(?:access_token|api[_-]?key|token|secret|password)=/i',parse_url($endpoint,PHP_URL_QUERY)??''))throw new \RuntimeException();}catch(\Throwable $e){return back()->withErrors(['endpoint'=>'Use a public HTTPS endpoint. Put credentials only in the private token field.']);}
  $mapping=json_decode($d['mapping']??'{}',true)?:[];if(!is_array($mapping)||array_is_list($mapping)&&$mapping)return back()->withErrors(['mapping'=>'Mapping must be a JSON object.']);foreach($mapping as $key=>$value)if(!in_array($key,['name','website','source_url','niche','location','country','email','phone','description','source_id'],true)||!is_string($value)||!ConnectorService::validPath($value))return back()->withErrors(['mapping'=>'Mappings must be safe JSON field paths.']);
  if(!empty($d['items_path'])&&!ConnectorService::validPath($d['items_path']))return back()->withErrors(['items_path'=>'Use a simple nested JSON array path.']);
  $body=json_decode($d['request_body']??'{}',true)?:[];if(preg_match('/"(?:password|secret|access_token|api_key)"\s*:/i',json_encode($body)))return back()->withErrors(['request_body'=>'Keep credentials out of request bodies; use the private token field.']);
  if($d['platform']==='Apollo.io'){
   if($d['purpose']!=='feed')return back()->withErrors(['purpose'=>'Apollo People Search must be a feed.']);
   $endpoint=ConnectorService::APOLLO_URL;$d['method']='POST';$d['auth_header']='x-api-key';$d['items_path']='people';
   if(!$mapping)$mapping=ConnectorService::apolloMapping();
   if(!$body)$body=['page'=>1,'per_page'=>10,'organization_not_locations'=>['India','Pakistan']];
  }
  $connector??=new ConnectorConfig;if(!$connector->exists)$connector->id=(string)Str::uuid();$connector->fill(['name'=>$d['name'],'platform'=>$d['platform'],'purpose'=>$d['purpose'],'endpoint'=>$endpoint,'enabled'=>$r->boolean('enabled'),'settings'=>['items_path'=>$d['items_path']??'','mapping'=>$mapping,'method'=>$d['method'],'request_body'=>$body,'auth_header'=>$d['auth_header'],'channel'=>$d['channel']??'custom','revision'=>(string)Str::uuid()]]);$connector->save();
  if(!empty($d['token']))IntegrationSecret::updateOrCreate(['key'=>'cn_'.str_replace('-','',$connector->id)],['data'=>['token'=>$d['token']]]);
  \App\Models\ChannelMessage::where('connector_id',$connector->id)->where('status','approved')->update(['status'=>'draft','approval_hash'=>null,'approved_at'=>null]);return back()->with('notice','Connector saved. This records an approved gateway/feed configuration, not a claim of native access to every listed platform. Changed gateways require fresh message approvals.');
 }
 public function toggle(ConnectorConfig $connector){$connector->enabled=!$connector->enabled;$connector->save();\App\Models\ChannelMessage::where('connector_id',$connector->id)->where('status','approved')->update(['status'=>'draft','approval_hash'=>null,'approved_at'=>null]);return back()->with('notice','Connector state changed.');}
 public function run(ConnectorConfig $connector,ConnectorService $service){try{$r=$service->feed($connector);return back()->with('notice','Feed: '.$r['imported'].' added, '.$r['duplicates'].' merged, '.$r['rejected'].' rejected.');}catch(ConnectorFailure $e){return back()->withErrors(['connector'=>'Connector not run: '.$e->getMessage()]);}catch(\Throwable $e){return back()->withErrors(['connector'=>'Connector not run: CHECK_CONFIGURATION']);}}
 public function test(ConnectorConfig $connector,ConnectorService $service){try{$result=$service->testConnection($connector);return back()->with('notice','Connection test: HTTP '.$result['http_status'].'; '.$result['people_count'].' rows received. No leads imported.');}catch(ConnectorFailure $e){return back()->withErrors(['connector'=>'Connection test: '.$e->getMessage().'. No leads imported.']);}}
 public function crm(Request $r,ConnectorConfig $connector,ConnectorService $service){$d=$r->validate(['lead_id'=>'required|integer|exists:leads,id','share_confirmed'=>'accepted']);$ok=$service->pushCrm($connector,Lead::findOrFail($d['lead_id']));return back()->with('notice',$ok?'CRM endpoint acknowledged this record.':'CRM delivery is unconfirmed. Inspect your destination before retrying.');}
}

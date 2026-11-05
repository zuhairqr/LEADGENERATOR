<?php
// Explicit, bounded provider test. Only fictional data is sent; local fixtures roll back.
require __DIR__.'/../vendor/autoload.php';
use App\Models\{Lead,BusinessRecord,WebsiteAudit};
use App\Services\{ProspectingProfile,ProposalWriter};
use Illuminate\Support\Facades\DB;
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!in_array('--ai',$argv,true)||!app()->environment('local')||config('database.connections.mysql.host')!=='127.0.0.1'||config('database.connections.mysql.port')!=3310)throw new RuntimeException('Explicit saved-provider approval and dedicated local database required.');
config(['database.connections.mysql.database'=>'lead_generator_test']);DB::purge('mysql');
$actual=str_replace('\\','/',DB::selectOne('SELECT @@datadir AS path')->path);
if(strcasecmp(rtrim($actual,'/'),str_replace('\\','/',dirname(__DIR__)).'/storage/local-mysql')||DB::getDatabaseName()!=='lead_generator_test')throw new RuntimeException('Wrong test database');
class BusinessProbeProfile extends ProspectingProfile {
 public function data():array{return ['business_name'=>'Fictional website service fixture','verified_facts'=>'Synthetic test data only. This fictional service offers website redesign and accessible mobile layouts. No portfolio, past clients or promised results.','signature'=>'Fictional fixture','profile_verified'=>true,'ai_consent'=>true,'language'=>'English','skills'=>['Web design'=>['website','responsive layout']],'ai_model'=>'gpt-6.1-sol'];}
}
$app->instance(ProspectingProfile::class,new BusinessProbeProfile);
$evidence=['valid_ai_draft'=>false,'application_writer_tested'=>true,'saved_connection_reused'=>true,'synthetic_input_only'=>true,'emails_sent'=>0,'checked_at'=>gmdate('c')];
DB::beginTransaction();
try{$stage='business_fixture';
 $business=BusinessRecord::create(['identity_key'=>hash('sha256',random_bytes(32)),'name'=>'Fictional Clinic','website'=>'https://fictional-clinic.invalid','domain'=>'fictional-clinic.invalid','country_code'=>'CA','geo_status'=>'allowed','country_evidence'=>'Fictional fixture country']);
 $stage='lead_fixture';$lead=new Lead(['source'=>'synthetic-test','source_id'=>bin2hex(random_bytes(12)),'title'=>'Fictional clinic business prospect','description'=>'Synthetic business with a website observation. This is not a posted request and not a real client.','url'=>$business->website,'url_hash'=>hash('sha256',random_bytes(32)),'matched_services'=>[],'match_reason'=>'Fictional business fixture only']);$lead->kind='business';$lead->business_record_id=$business->id;$lead->save();
 WebsiteAudit::create(['lead_id'=>$lead->id,'requested_url'=>$business->website,'final_url'=>$business->website,'status'=>'completed','http_status'=>200,'score'=>80,'evidence'=>['synthetic_test_only'=>true,'scope'=>'Fictional static HTML fixture; no live measurement','viewport'=>null,'h1_count'=>1],'findings'=>[['code'=>'VIEWPORT_MISSING','observation'=>'Fictional HTML fixture lacks a viewport meta tag; rendered mobile behavior was not tested.','possible_service'=>'Review responsive website layout']]]);
 $stage='proposal_writer';$result=app(ProposalWriter::class)->draft($lead);$evidence['valid_ai_draft']=true;$evidence['qualified']=$result['qualified'];$evidence['proposal_characters']=mb_strlen($result['proposal']);$evidence['email_characters']=mb_strlen($result['email_body']);
 file_put_contents(storage_path('n8n/logs/business-ai-synthetic-draft.json'),json_encode(['test_only'=>true,'not_operator_business_facts'=>true,'sent'=>false,'result'=>$result],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}catch(Throwable $e){$evidence['stage']=$stage;$evidence['exception_class']=get_class($e);$evidence['exception_code']=(string)$e->getCode();$evidence['error_code']=in_array($e->getMessage(),['AI_BRIDGE_FAILED','BUSINESS_PROFILE_REQUIRED','BUSINESS_AUDIT_REQUIRED','AI returned an invalid response.','AI response failed validation.'],true)?$e->getMessage():'BUSINESS_AI_PROBE_FAILED';}
finally{DB::rollBack();}
file_put_contents(storage_path('n8n/logs/business-ai-probe.json'),json_encode($evidence,JSON_PRETTY_PRINT));echo json_encode($evidence).PHP_EOL;exit($evidence['valid_ai_draft']?0:1);

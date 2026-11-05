<?php
// Bounded live probe of the actual writer. Fictional profile stays in this process.
require __DIR__.'/../vendor/autoload.php';
use App\Services\{ProspectingProfile,ProposalWriter};
use App\Models\Lead;
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!in_array('--ai',$argv,true)){fwrite(STDERR,"Use --ai only after saved-provider reuse approval.\n");exit(1);}
class LiveSalesFixtureProfile extends ProspectingProfile {
    public function data():array{return ['business_name'=>'Synthetic Laravel service fixture — not the operator business',
        'verified_facts'=>'Fictional test fixture only: provides Laravel and Stripe checkout development. Do not attribute these facts to the operator.',
        'signature'=>'Synthetic test fixture','profile_verified'=>true,'ai_consent'=>true,'language'=>'English',
        'skills'=>['Laravel'=>['laravel'],'Payment integration'=>['stripe','checkout']], 'ai_model'=>'gpt-6.1-sol'];}
}
$app->instance(ProspectingProfile::class,new LiveSalesFixtureProfile);
$lead=new Lead(['title'=>'Laravel checkout repair — synthetic buyer request',
    'description'=>'Fictional test request: we need a Laravel freelancer to repair duplicate Stripe checkout callbacks. Ask about our Laravel version and current webhook handling. No budget or deadline has been confirmed. This is test data, not a real client.']);
$evidence=['valid_ai_draft'=>false,'application_writer_tested'=>true,'saved_connection_reused'=>true,'synthetic_input_only'=>true,'emails_sent'=>0,'checked_at'=>gmdate('c')];
try {
    $result=app(ProposalWriter::class)->draft($lead);$evidence['valid_ai_draft']=true;
    $evidence['proposal_characters']=mb_strlen($result['proposal']);$evidence['email_characters']=mb_strlen($result['email_body']);
    file_put_contents(storage_path('n8n/logs/sales-ai-synthetic-draft.json'),json_encode(['test_only'=>true,'not_operator_business_facts'=>true,'sent'=>false,'result'=>$result],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    $evidence['error_code']=in_array($e->getMessage(),['AI_BRIDGE_FAILED','BUSINESS_PROFILE_REQUIRED','AI returned an invalid response.','AI response failed validation.'],true)?$e->getMessage():'AI_PROBE_FAILED';
}
file_put_contents(storage_path('n8n/logs/sales-ai-probe.json'),json_encode($evidence,JSON_PRETTY_PRINT));
echo json_encode($evidence).PHP_EOL;exit($evidence['valid_ai_draft']?0:1);

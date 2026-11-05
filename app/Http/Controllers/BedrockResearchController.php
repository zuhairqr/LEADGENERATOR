<?php
namespace App\Http\Controllers;

use App\Models\{IntegrationSecret, IntegrationSetting};
use App\Services\BedrockResearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BedrockResearchController
{
    public function save(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'region'=>['required', Rule::in(BedrockResearchService::REGIONS)],
            'key'=>['nullable','string','max:2048','not_regex:/[\r\n]/'],
        ]);
        if ($validator->fails()) return back()->withErrors($validator);
        $data = $validator->validated();
        IntegrationSetting::updateOrCreate(['key'=>'bedrock_web_research'], ['data'=>['region'=>$data['region'], 'model'=>BedrockResearchService::MODEL]]);
        if (!empty($data['key'])) IntegrationSecret::updateOrCreate(['key'=>'bedrock_web_research'], ['data'=>['key'=>$data['key']]]);
        return back()->with('notice','Bedrock research settings saved privately. No model request or lead import was made.');
    }

    public function research(Request $request, BedrockResearchService $service)
    {
        $data = $request->validate(['question'=>['required','string','min:8','max:300']]);
        try {
            $result = $service->research($data['question']);
            return back()->with('bedrock_research', $result)->with('notice','Cited Bedrock research returned. Review sources; no lead was imported.');
        } catch (\Throwable $error) {
            $code = $error->getMessage();
            return back()->withErrors(['bedrock'=>preg_match('/^BEDROCK_[A-Z0-9_]+$/', $code) ? $code : 'BEDROCK_RESEARCH_FAILED']);
        }
    }
}

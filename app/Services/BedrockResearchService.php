<?php
namespace App\Services;

use App\Models\{IntegrationSecret, IntegrationSetting};
use Illuminate\Support\Facades\Http;

/** One bounded, cited research answer. Search observations are never bulk-imported. */
class BedrockResearchService
{
    public const REGIONS = ['us-east-1', 'us-east-2', 'us-west-2'];
    public const MODEL = 'openai.gpt-5.4';

    public function configured(): bool
    {
        return IntegrationSecret::whereKey('bedrock_web_research')->exists();
    }

    public function settings(): array
    {
        return IntegrationSetting::find('bedrock_web_research')?->data ?? ['region'=>'us-east-1', 'model'=>self::MODEL];
    }

    public function research(string $question): array
    {
        $question = trim($question);
        if (mb_strlen($question) < 8 || mb_strlen($question) > 300) throw new \RuntimeException('INVALID_RESEARCH_QUESTION');
        $settings = $this->settings();
        $region = $settings['region'] ?? '';
        $model = $settings['model'] ?? '';
        if (!in_array($region, self::REGIONS, true) || $model !== self::MODEL) throw new \RuntimeException('BEDROCK_SETTINGS_INVALID');
        $key = IntegrationSecret::find('bedrock_web_research')?->data['key'] ?? null;
        if (!is_string($key) || $key === '') throw new \RuntimeException('BEDROCK_KEY_NOT_CONFIGURED');

        $body = [
            'model'=>$model,
            'input'=>'Research one ecommerce or store discovery question. Return a short answer grounded only in cited public sources. Give business names and source URLs only when supported. Do not invent contact details, buying intent, or website defects. Do not create a list for bulk lead import. Question: '.$question,
            'max_output_tokens'=>500,
            'tools'=>[['type'=>'web_search', 'search_context_size'=>'low', 'external_web_access'=>false]],
        ];
        try {
            $response = Http::withToken($key)->acceptJson()->connectTimeout(5)->timeout(45)
                ->post("https://bedrock-mantle.{$region}.api.aws/openai/v1/responses", $body);
        } catch (\Throwable $e) {
            throw new \RuntimeException('BEDROCK_NETWORK_ERROR');
        }
        if (!$response->successful()) {
            $status = $response->status();
            if ($status === 401 && str_contains(strtolower((string)$response->json('error.message')), 'not available for this account')) {
                throw new \RuntimeException('BEDROCK_MODEL_UNAVAILABLE_FOR_ACCOUNT');
            }
            throw new \RuntimeException(in_array($status, [400,401,403,422,429], true) ? 'BEDROCK_HTTP_'.$status : 'BEDROCK_PROVIDER_ERROR');
        }
        $output = $response->json('output');
        if (!is_array($output)) throw new \RuntimeException('BEDROCK_RESPONSE_INVALID');
        $texts = []; $citations = [];
        foreach ($output as $item) {
            if (($item['type'] ?? '') !== 'message') continue;
            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? '') !== 'output_text') continue;
                if (is_string($part['text'] ?? null)) $texts[] = $part['text'];
                foreach ($part['annotations'] ?? [] as $annotation) {
                    if (($annotation['type'] ?? '') !== 'url_citation') continue;
                    $url = $annotation['url'] ?? '';
                    try { $url = PublicWebClient::url($url); } catch (\Throwable $e) { continue; }
                    if (parse_url($url, PHP_URL_SCHEME) !== 'https') continue;
                    $citations[$url] = ['url'=>$url, 'title'=>mb_substr((string)($annotation['title'] ?? $url), 0, 180)];
                }
            }
        }
        $answer = trim(implode("\n", $texts));
        if ($answer === '' || !$citations) throw new \RuntimeException('BEDROCK_CITATIONS_MISSING');
        return ['answer'=>mb_substr($answer, 0, 5000), 'citations'=>array_slice(array_values($citations), 0, 10), 'model'=>$model];
    }
}

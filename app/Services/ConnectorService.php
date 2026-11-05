<?php
namespace App\Services;

use App\Models\{ConnectorConfig, ConnectorRun, IntegrationSecret, Lead};
use Illuminate\Support\Facades\Cache;

class ConnectorService
{
    public const APOLLO_URL = 'https://api.apollo.io/api/v1/mixed_people/api_search';
    public function __construct(private PublicWebClient $web) {}

    public static function validHeader(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z][A-Za-z0-9-]{0,63}$/D', $name)
            && !in_array(strtolower($name), ['host','content-type','content-length','accept','cookie','set-cookie','connection','transfer-encoding','proxy-authorization'], true);
    }

    public static function validPath(string $path): bool
    {
        if (strlen($path) > 150 || !preg_match('/^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*$/D', $path)) return false;
        foreach (explode('.', $path) as $part) if (in_array(strtolower($part), ['__proto__','prototype','constructor'], true)) return false;
        return true;
    }

    public static function apolloMapping(): array
    {
        return ['name'=>'organization.name', 'website'=>'organization.website_url', 'country'=>'organization.country',
            'location'=>'organization.city', 'source_id'=>'id', 'description'=>'title'];
    }

    public function headers(ConnectorConfig $connector): array
    {
        $secret = IntegrationSecret::find('cn_'.str_replace('-', '', $connector->id))?->data ?? [];
        $name = $connector->platform === 'Apollo.io' ? 'x-api-key' : ($connector->settings['auth_header'] ?? 'Authorization');
        if (!self::validHeader($name)) throw new ConnectorFailure('INVALID_AUTH_HEADER');
        if ($connector->platform === 'Apollo.io' && empty($secret['token'])) throw new ConnectorFailure('API_KEY_NOT_SAVED');
        $headers = ['Accept: application/json'];
        if (!empty($secret['token'])) $headers[] = $name.': '.(strcasecmp($name, 'Authorization') === 0 ? 'Bearer ' : '').$secret['token'];
        return $headers;
    }

    private function request(ConnectorConfig $connector): array
    {
        $settings = $connector->settings ?? [];
        $mapping = $settings['mapping'] ?? [];
        if (!is_array($mapping)) throw new ConnectorFailure('INVALID_FIELD_MAPPING');
        foreach ($mapping as $path) if (!is_string($path) || !self::validPath($path)) throw new ConnectorFailure('INVALID_FIELD_MAPPING');
        $apollo = $connector->platform === 'Apollo.io';
        $method = $apollo ? 'POST' : ($settings['method'] ?? 'GET');
        $body = null;
        if ($method === 'POST') {
            $payload = $settings['request_body'] ?? [];
            if (!is_array($payload)) throw new ConnectorFailure('INVALID_REQUEST_JSON');
            if ($apollo && !$payload) $payload = ['page'=>1, 'per_page'=>10, 'organization_not_locations'=>['India','Pakistan']];
            $body = json_encode($payload ?: new \stdClass, JSON_THROW_ON_ERROR);
        }
        $headers = $this->headers($connector);
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        try {
            $response = $this->web->fetch($apollo ? self::APOLLO_URL : $connector->endpoint, 2097152, $headers, $method, $body);
        } catch (ConnectorFailure $e) { throw $e; }
        catch (\Throwable $e) { throw new ConnectorFailure('NETWORK_OR_ENDPOINT_ERROR'); }
        $status = (int) ($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            $codes = [400=>'BAD_REQUEST', 401=>'INVALID_API_KEY', 403=>'ACCESS_DENIED_OR_SCOPE', 422=>'INVALID_SEARCH_FILTERS', 429=>'RATE_LIMITED'];
            throw new ConnectorFailure($codes[$status] ?? 'PROVIDER_HTTP_ERROR', $status ?: null);
        }
        try { $json = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { throw new ConnectorFailure('INVALID_RESPONSE_JSON', $status); }
        $path = $apollo ? 'people' : ($settings['items_path'] ?? '');
        if ($path !== '' && !self::validPath($path)) throw new ConnectorFailure('INVALID_ITEMS_PATH', $status);
        $items = $path === '' ? $json : data_get($json, $path);
        if (!is_array($items) || !array_is_list($items)) throw new ConnectorFailure('INVALID_PEOPLE_ARRAY', $status);
        return ['items'=>$items, 'status'=>$status];
    }

    private function limited(ConnectorConfig $connector): void
    {
        if (ConnectorRun::where('connector_id', $connector->id)->where('created_at', '>', now()->subHours(24))->count() >= 4) throw new ConnectorFailure('CONNECTOR_DAILY_LIMIT');
    }

    private function recordFailure(ConnectorConfig $connector, ConnectorRun $run, \Throwable $error): void
    {
        $code = $error instanceof ConnectorFailure ? $error->safeCode : 'FEED_FETCH_OR_MAPPING_FAILED';
        $status = $error instanceof ConnectorFailure ? $error->httpStatus : null;
        $failures = ConnectorRun::where('connector_id', $connector->id)->where('status','failed')->where('created_at','>',now()->subDay())->count();
        $run->update(['status'=>'failed','error_code'=>$status ? "HTTP_{$status}_{$code}" : $code,
            'retry_after'=>now()->addMinutes(min(60, 2 ** min(5, $failures + 1)))]);
    }

    public function testConnection(ConnectorConfig $connector): array
    {
        if ($connector->purpose !== 'feed') throw new ConnectorFailure('FEED_ONLY_TEST');
        $lock = Cache::lock('connector:'.$connector->id, 60);
        if (!$lock->get()) throw new ConnectorFailure('CONNECTOR_BUSY');
        try {
            $this->limited($connector);
            $run = ConnectorRun::create(['connector_id'=>$connector->id,'status'=>'testing']);
            try {
                $result = $this->request($connector);
                $run->update(['status'=>'test_passed']);
                return ['http_status'=>$result['status'],'people_count'=>count($result['items'])];
            } catch (\Throwable $e) {
                $this->recordFailure($connector, $run, $e);
                throw $e instanceof ConnectorFailure ? $e : new ConnectorFailure('CONNECTOR_TEST_FAILED');
            }
        } finally { $lock->release(); }
    }

    public function feed(ConnectorConfig $connector): array
    {
        if (!$connector->enabled || $connector->purpose !== 'feed') throw new ConnectorFailure('CONNECTOR_NOT_ENABLED');
        $lock = Cache::lock('connector:'.$connector->id, 60);
        if (!$lock->get()) throw new ConnectorFailure('CONNECTOR_BUSY');
        try {
            $last = ConnectorRun::where('connector_id',$connector->id)->latest()->first();
            if ($last?->retry_after?->isFuture()) throw new ConnectorFailure('CONNECTOR_BACKOFF');
            $this->limited($connector);
            $run = ConnectorRun::create(['connector_id'=>$connector->id,'status'=>'running']);
            $counts = ['imported'=>0,'duplicates'=>0,'rejected'=>0];
            try {
                $items = $this->request($connector)['items'];
                $mapping = $connector->settings['mapping'] ?? [];
                if ($connector->platform === 'Apollo.io' && !$mapping) $mapping = self::apolloMapping();
                foreach ($mapping as $path) if (!is_string($path) || !self::validPath($path)) throw new ConnectorFailure('INVALID_FIELD_MAPPING');
                foreach (array_slice($items, 0, 100) as $row) {
                    if (!is_array($row)) { $counts['rejected']++; continue; }
                    $mapped = [];
                    foreach (['name','website','source_url','niche','location','country','email','phone','description','source_id'] as $field) {
                        $value = data_get($row, $mapping[$field] ?? $field);
                        if (is_scalar($value)) $mapped[$field] = (string) $value;
                    }
                    $mapped['attribution'] = 'Imported from approved '.$connector->platform.' connector: '.$connector->name;
                    $mapped['source_url'] ??= $mapped['website'] ?? $connector->endpoint;
                    try {
                        $result = app(BusinessIntake::class)->import($mapped, 'feed-'.substr(str_replace('-', '', $connector->id), 0, 20));
                        $result['duplicate'] ? $counts['duplicates']++ : $counts['imported']++;
                    } catch (\Throwable $e) { $counts['rejected']++; }
                }
                $run->update($counts + ['status'=>'completed']);
                return $counts;
            } catch (\Throwable $e) {
                $this->recordFailure($connector, $run, $e);
                throw $e instanceof ConnectorFailure ? $e : new ConnectorFailure('FEED_FETCH_OR_MAPPING_FAILED');
            }
        } finally { $lock->release(); }
    }

    public function pushCrm(ConnectorConfig $c, Lead $lead): bool
    {
        if (!$c->enabled || $c->purpose !== 'crm') throw new \RuntimeException('CRM_CONNECTOR_NOT_ENABLED');
        $payload = ['event'=>'lead.updated','id'=>$lead->id,'kind'=>$lead->kind,'title'=>$lead->title,'status'=>$lead->status,'source'=>$lead->source,'business'=>$lead->business?->only(['name','website','niche','location','country_code']),'contact_email'=>$lead->contact_email,'reply_intent'=>$lead->reply_intent,'next_action_at'=>$lead->next_action_at,'updated_at'=>$lead->updated_at];
        $run = ConnectorRun::create(['connector_id'=>$c->id,'status'=>'running']);
        try {
            $r = $this->web->fetch($c->endpoint,262144,array_merge($this->headers($c),['Content-Type: application/json','Idempotency-Key: lead-'.$lead->id.'-'.$lead->updated_at->timestamp]),'POST',json_encode($payload));
            if ($r['status'] < 200 || $r['status'] >= 300) throw new \RuntimeException('CRM_REJECTED');
            $run->update(['status'=>'completed','imported'=>1]); return true;
        } catch (\Throwable $e) { $run->update(['status'=>'delivery_unknown','error_code'=>'CRM_ACKNOWLEDGEMENT_MISSING']); return false; }
    }

    public function scheduled(): void
    {
        $c = ConnectorConfig::where('purpose','feed')->where('enabled',true)->whereNotIn('id',ConnectorRun::where('created_at','>',now()->subHours(6))->select('connector_id'))->oldest('updated_at')->first();
        if ($c) try { $this->feed($c); } catch (\Throwable $e) {}
    }
}

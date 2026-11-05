<?php
namespace App\Http\Controllers;

use App\Models\CollectionRun;
use App\Services\LeadMatcher;
use App\Services\ProspectingProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CollectionController {
    public function sources() {
        $sources = [];
        $selected = app(ProspectingProfile::class)->data()['enabled_sources'] ?? null;
        foreach (config('leadgen.sources', []) as $id => $source) {
            $last = CollectionRun::where('source', $id)->latest()->first();
            $enabled = ($source['permitted'] ?? false) === true && (!is_array($selected) || in_array($id, $selected, true));
            $sources[] = ['id' => $id, 'label' => $source['label'] ?? $id,
                'enabled' => $enabled,
                'kind' => $source['kind'] ?? 'manual', 'pending' => $source['pending'] ?? null,
                'last_run' => $last ? $last->only(['id', 'status', 'fetched', 'matched', 'imported', 'duplicates', 'rejected', 'error_code', 'created_at']) : null];
        }
        return response()->json(['sources' => $sources]);
    }

    public function claim(string $source) {
        $config = config('leadgen.sources.'.$source);
        abort_unless(is_array($config), 404);
        $profile = app(ProspectingProfile::class);
        $selected = $profile->data()['enabled_sources'] ?? null;
        if (is_array($selected) && !in_array($source, $selected, true)) return response()->json(['allowed'=>false,'source'=>$source,'reason'=>'Paused in your skill-search settings.']);
        if (($config['permitted'] ?? false) !== true || ($config['kind'] ?? '') === 'meta') {
            return response()->json(['allowed' => false, 'source' => $source, 'reason' => $config['pending'] ?? 'Source approval is required.']);
        }
        // The shared persistent gate covers both manual and scheduled n8n runs.
        // A failed fetch still consumes a slot; retries must not hammer the provider.
        $lock = Cache::lock('collection-claim:'.$source, 15);
        if (!$lock->get()) return response()->json(['allowed' => false, 'source' => $source, 'reason' => 'Another run is claiming this source.']);
        try {
            $recent = CollectionRun::where('source', $source)->where('created_at', '>', now()->subHours(24));
            $last = (clone $recent)->latest()->first();
            $cooldown = $config['cooldown_minutes'] ?? 1;
            if ($recent->count() >= 4 || ($last && $last->created_at->gt(now()->subMinutes($cooldown)))) {
                return response()->json(['allowed' => false, 'source' => $source, 'reason' => 'Source cooldown or rolling daily limit is still active.']);
            }
            $count = CollectionRun::where('source', $source)->count();
            $run = CollectionRun::create(['id' => (string) Str::uuid(), 'source' => $source]);
            $query = null;
            $url = $config['fetch_url'] ?? null;
            if (($config['kind'] ?? '') === 'search') {
                $query = $profile->query($source, $config, $count);
                $url = 'http://searxng:8080/search?'.http_build_query(['q' => $query, 'format' => 'json', 'time_range' => 'month', 'safesearch' => 1]);
            }
            return response()->json(['allowed' => true, 'run_id' => $run->id, 'source' => $source,
                'kind' => $config['kind'], 'fetch_url' => $url, 'query' => $query, 'filters'=>$profile->filters()]);
        } finally { $lock->release(); }
    }

    public function complete(Request $request, CollectionRun $run, LeadMatcher $matcher) {
        $data = $request->validate(['leads' => ['present', 'array', 'max:30'],
            'leads.*' => ['array'], 'fetched' => ['required', 'integer', 'min:0', 'max:100000'],
            'error_code' => ['nullable', 'string', 'regex:/^[A-Z0-9_]{1,80}$/']]);
        $lock = Cache::lock('collection-complete:'.$run->id, 60);
        abort_unless($lock->get(), 409, 'This run is being completed.');
        try {
            $run->refresh();
            if ($run->finished_at) return response()->json($run->only(['id', 'source', 'status', 'imported', 'duplicates', 'rejected']));
            $source = config('leadgen.sources.'.$run->source);
            abort_unless(($source['permitted'] ?? false) === true, 422, 'Source is no longer approved.');
            $imported = $duplicates = $rejected = 0;
            foreach ($data['leads'] as $lead) {
                if (($lead['source'] ?? null) !== $run->source) { $rejected++; continue; }
                $item = Request::create('/api/leads/import', 'POST', $lead);
                $item->headers->set('Accept', 'application/json');
                try {
                    $response = app(ImportController::class)($item, $matcher);
                    $result = $response->getData(true);
                    if ($result['duplicate'] ?? false) $duplicates++; else $imported++;
                } catch (ValidationException|HttpException $error) { $rejected++; }
            }
            if ($run->source === 'jobicy' && empty($data['error_code'])) {
                $this->refreshJobicyAvailability();
            }
            $run->update(['fetched' => $data['fetched'], 'matched' => count($data['leads']),
                'imported' => $imported, 'duplicates' => $duplicates, 'rejected' => $rejected,
                'error_code' => $data['error_code'] ?? null, 'finished_at' => now(),
                'status' => !empty($data['error_code']) ? 'failed' : ($rejected ? 'completed_with_rejections' : 'completed')]);
            return response()->json($run->only(['id', 'source', 'status', 'fetched', 'matched', 'imported', 'duplicates', 'rejected', 'error_code']));
        } finally { $lock->release(); }
    }

    private function refreshJobicyAvailability(): void
    {
        $query = \App\Models\Lead::where('source', 'jobicy');
        $ids = (clone $query)->latest('id')->limit(100)->pluck('source_id')
            ->filter(fn ($id) => preg_match('/^[1-9][0-9]*$/D', $id))->all();
        if (!$ids) {
            return;
        }

        try {
            $response = Http::acceptJson()->withOptions(['allow_redirects' => false])
                ->connectTimeout(3)->timeout(8)->get('https://jobicy.com/api/jobs/status', ['ids' => implode(',', $ids)]);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return;
        }
        $jobs = $response->json('jobs');
        if (!$response->successful() || !is_array($jobs)) {
            return;
        }
        $closed = [];
        $active = [];
        foreach ($jobs as $job) {
            $id = (string) ($job['id'] ?? '');
            if (!in_array($id, $ids, true)) {
                continue;
            }
            if (($job['status'] ?? null) === 'closed') {
                $closed[] = $id;
            } elseif (($job['status'] ?? null) === 'active') {
                $active[] = $id;
            }
        }
        (clone $query)->whereIn('source_id', $closed)->whereNull('listing_expires_at')->update(['listing_expires_at' => now()]);
        $query->whereIn('source_id', $active)->whereNotNull('listing_expires_at')->update(['listing_expires_at' => null]);
    }
}

<?php
namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\LeadMatcher;
use App\Services\LeadQualityGate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImportController
{
    public function __invoke(Request $request, LeadMatcher $matcher)
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/'],
            'source_id' => ['required', 'string', 'max:190'],
            'url' => ['required', 'url:http,https', 'max:2048'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:15000'],
            'budget_min' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'budget_max' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'published_at' => ['nullable', 'date', 'after_or_equal:2000-01-01', 'before_or_equal:now'],
            'listing_expires_at' => ['nullable', 'date', 'after:now'],
            'country_code' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
        ]);
        $source = config('leadgen.sources.'.$data['source']);
        $host = strtolower((string) parse_url($data['url'], PHP_URL_HOST));
        $issuerNotice = in_array($host, $source['issuer_hosts'] ?? [], true);
        abort_unless(is_array($source) && ($source['permitted'] ?? false) === true
            && ($issuerNotice || in_array($host, $source['hosts'] ?? [], true)), 422, 'Source is not approved or listing host is not allowed.');
        if ($issuerNotice && empty($data['listing_expires_at'])) {
            throw ValidationException::withMessages(['listing_expires_at' => 'A verified closing deadline is required for issuer notices.']);
        }
        if (!$issuerNotice && empty($source['tracks_expiry'])) {
            unset($data['listing_expires_at']);
        }
        if (!$issuerNotice) {
            unset($data['country_code']);
        } elseif (in_array($data['country_code'] ?? null, app(\App\Services\CountryPolicy::class)->excluded(), true)) {
            throw ValidationException::withMessages(['country_code' => 'The project country is excluded.']);
        }
        if (isset($data['budget_min'], $data['budget_max']) && $data['budget_max'] < $data['budget_min']) {
            throw ValidationException::withMessages(['budget_max' => 'Maximum must be at least minimum.']);
        }
        if ((isset($data['budget_min']) || isset($data['budget_max'])) && empty($data['currency'])) {
            throw ValidationException::withMessages(['currency' => 'Currency is required when a budget is provided.']);
        }
        try { $data['url_hash'] = $matcher->fingerprint($data['url']); }
        catch (\InvalidArgumentException $e) { throw ValidationException::withMessages(['url' => $e->getMessage()]); }
        $data += $matcher->score($data['title'], $data['description']);
        $qualityReason = app(LeadQualityGate::class)->reason($data['source'], $data['title'], $data['description'], $data['matched_services']);
        if ($qualityReason !== null) throw ValidationException::withMessages(['lead_quality' => $qualityReason]);
        $duplicate = fn () => Lead::where('url_hash', $data['url_hash'])
            ->orWhere(fn ($q) => $q->where('source', $data['source'])->where('source_id', $data['source_id']))->first();
        if ($lead = $duplicate()) {
            if ($lead->source === $data['source'] && isset($data['listing_expires_at'])) {
                $lead->update(['listing_expires_at' => $data['listing_expires_at']]);
            }
            return response()->json(['id' => $lead->id, 'duplicate' => true]);
        }
        try { $lead = Lead::create($data); }
        catch (UniqueConstraintViolationException $e) {
            // Database constraints also protect concurrent n8n retries.
            $lead = $duplicate();
            if (!$lead) throw $e;
            return response()->json(['id' => $lead->id, 'duplicate' => true]);
        }
        return response()->json(['id' => $lead->id, 'duplicate' => false, 'match_score' => $lead->match_score], 201);
    }
}

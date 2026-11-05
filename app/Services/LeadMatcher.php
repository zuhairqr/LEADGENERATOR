<?php
namespace App\Services;

/** Explainable keyword screening, NOT an AI confidence or probability. */
class LeadMatcher
{
    private const GROUPS = [
        'Laravel' => ['laravel', 'livewire', 'filament'],
        'Websites' => ['website', 'web development', 'wordpress', 'ecommerce', 'e-commerce', 'shopify', 'landing page'],
        'AI automation' => ['n8n', 'workflow automation', 'ai automation', 'zapier', 'make.com'],
        'AI integration' => ['ai integration', 'openai', 'chatbot', 'llm', 'rag', 'gpt'],
        'Merchant integration' => ['payment gateway', 'merchant integration', 'stripe', 'paypal', 'checkout', 'payment integration'],
    ];

    public function score(string $title, string $description): array
    {
        $text = strtolower($title.' '.$description);
        $services = [];
        $groups = function_exists('app') && app()->bound('db') ? app(ProspectingProfile::class)->skills() : self::GROUPS;
        foreach ($groups as $service => $terms) {
            foreach ($terms as $term) {
                if (preg_match('/(?<![a-z0-9])'.preg_quote($term, '/').'(?![a-z0-9])/i', $text)) {
                    $services[] = $service;
                    break;
                }
            }
        }
        return [
            'match_score' => $services ? min(100, 40 + count($services) * 12) : 0,
            'matched_services' => $services,
            'match_reason' => $services
                ? 'Keyword matches: '.implode(', ', $services).'. Budget, intent and eligibility require review.'
                : 'No configured service keywords found. Manual review may still identify a fit.',
        ];
    }

    public function fingerprint(string $url): string
    {
        // Remove fragments and known tracking parameters; preserve meaningful query values.
        $parts = parse_url($url);
        if (!$parts || !isset($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('A public HTTP(S) listing URL is required.');
        }
        $query = [];
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            if ($pair === '') continue;
            $key = strtolower(urldecode(explode('=', $pair, 2)[0]));
            if (str_starts_with($key, 'utm_') || in_array($key, ['fbclid', 'gclid'], true)) continue;
            $query[] = $pair;
        }
        sort($query, SORT_STRING);
        $port = isset($parts['port']) && !in_array($parts['port'], [80, 443], true) ? ':'.$parts['port'] : '';
        $canonical = strtolower($parts['host']).$port.($parts['path'] ?? '/').($query ? '?'.implode('&', $query) : '');
        return hash('sha256', $canonical);
    }
}

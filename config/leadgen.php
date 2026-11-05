<?php
return [
    'admin_email' => env('LEAD_ADMIN_EMAIL', ''),
    'admin_password_hash' => env('LEAD_ADMIN_PASSWORD_HASH', ''),
    'import_token' => env('LEAD_IMPORT_TOKEN', ''),
    'sales_bridge' => env('LEAD_SALES_BRIDGE', 'http://127.0.0.1:5679/webhook'),
    'openai_key' => env('OPENAI_API_KEY', ''),
    'openai_model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
    // Only verified business facts; no client secrets or private contact information.
    'portfolio' => env('LEAD_VERIFIED_PORTFOLIO', ''),
    // Enable a source ONLY after reviewing its current automation and data-use terms.
    // Hosts must match listing URLs exactly. No source is approved by default.
    'sources' => [
        'business-web'=>['label'=>'Public business websites','kind'=>'business','permitted'=>(bool)env('LEAD_BUSINESS_WEB_APPROVED',false),'hosts'=>[]],
        'remotive' => [
            'label' => 'Remotive contracts', 'kind' => 'remotive',
            'permitted' => (bool) env('LEAD_REMOTIVE_APPROVED', false),
            'hosts' => ['remotive.com'],
            'fetch_url' => 'https://remotive.com/api/remote-jobs',
        ],
        'remoteok' => [
            'label' => 'Remote OK contracts', 'kind' => 'remoteok',
            'permitted' => (bool) env('LEAD_REMOTEOK_APPROVED', false),
            'hosts' => ['remoteok.com', 'www.remoteok.com'],
            'fetch_url' => 'https://remoteok.com/api',
        ],
        'arbeitnow' => [
            'label' => 'Arbeitnow remote contracts', 'kind' => 'arbeitnow',
            'permitted' => (bool) env('LEAD_ARBEITNOW_APPROVED', false),
            'hosts' => ['arbeitnow.com', 'www.arbeitnow.com'],
            'fetch_url' => 'https://www.arbeitnow.com/api/job-board-api',
        ],
        'jobicy' => [
            'label' => 'Jobicy contracts', 'kind' => 'jobicy',
            'permitted' => (bool) env('LEAD_JOBICY_APPROVED', false),
            'hosts' => ['jobicy.com', 'www.jobicy.com'],
            'fetch_url' => 'https://jobicy.com/api/jobs?count=60&industry=engineering',
            'cooldown_minutes' => 60,
            'apply_on_source' => true,
        ],
        'weworkremotely' => [
            'label' => 'We Work Remotely contracts', 'kind' => 'weworkremotely',
            'permitted' => (bool) env('LEAD_WWR_APPROVED', false),
            'hosts' => ['weworkremotely.com', 'www.weworkremotely.com'],
            'fetch_url' => 'https://weworkremotely.com/remote-jobs.rss',
            'tracks_expiry' => true,
            'apply_on_source' => true,
        ],
        'himalayas' => [
            'label' => 'Himalayas contracts', 'kind' => 'himalayas',
            'permitted' => (bool) env('LEAD_HIMALAYAS_APPROVED', false),
            'hosts' => ['himalayas.app', 'www.himalayas.app'],
            'fetch_url' => 'https://himalayas.app/jobs/api/search?employment_type=Contractor&sort=recent',
            'cooldown_minutes' => 1440,
            'tracks_expiry' => true,
            'apply_on_source' => true,
        ],
        'freelancer' => [
            'label' => 'Freelancer.com projects', 'kind' => 'marketplace', 'permitted' => false,
            'pending' => 'The SDK is free. Project search needs authorized Freelancer API access and an OAuth token.',
        ],
        'upwork' => [
            'label' => 'Upwork jobs', 'kind' => 'marketplace', 'permitted' => false,
            'pending' => 'Needs an Upwork-approved API key for this account and use case.',
        ],
        'facebook-public' => [
            'label' => 'Facebook public requests', 'kind' => 'search',
            'permitted' => (bool) env('LEAD_FACEBOOK_PUBLIC_APPROVED', false),
            'hosts' => ['facebook.com', 'www.facebook.com', 'm.facebook.com'],
            'search_scope' => 'site:facebook.com',
        ],
        'instagram-public' => [
            'label' => 'Instagram public requests', 'kind' => 'search',
            'permitted' => (bool) env('LEAD_INSTAGRAM_PUBLIC_APPROVED', false),
            'hosts' => ['instagram.com', 'www.instagram.com'],
            'search_scope' => 'site:instagram.com',
        ],
        'public-web' => [
            'label' => 'Public project requests', 'kind' => 'search',
            'permitted' => (bool) env('LEAD_PUBLIC_WEB_APPROVED', false),
            'hosts' => ['reddit.com', 'www.reddit.com', 'old.reddit.com', 'linkedin.com', 'www.linkedin.com', 'news.ycombinator.com'],
            // Direct issuer notices reviewed against their published procurement terms.
            'issuer_hosts' => ['ruralhome.org', 'www.docomomo-us-mn.org', 'www.eiturbanmobility.eu', 'www.wavetransit.com', 'www.achievementfirst.org'],
            'search_scope' => '(site:reddit.com OR site:linkedin.com OR site:news.ycombinator.com)',
            'queries' => ['site:reddit.com/r/forhire "[Hiring]" "web developer"', 'site:linkedin.com/posts "looking for" "Laravel developer"'],
        ],
        'hackernews' => [
            'label' => 'Hacker News Ask requests', 'kind' => 'hackernews',
            'permitted' => (bool) env('LEAD_HN_APPROVED', false),
            'hosts' => ['news.ycombinator.com'],
            'fetch_url' => 'https://hacker-news.firebaseio.com/v0/askstories.json',
        ],
        'facebook-inbox' => [
            'label' => 'Facebook Page messages/comments', 'kind' => 'meta',
            'permitted' => false, 'hosts' => ['facebook.com', 'www.facebook.com'],
            'pending' => 'Connect your Meta app and Page, grant the required read permissions, and configure a public HTTPS webhook.',
        ],
        'instagram-inbox' => [
            'label' => 'Instagram professional messages/comments', 'kind' => 'meta',
            'permitted' => false, 'hosts' => ['instagram.com', 'www.instagram.com'],
            'pending' => 'Connect your Instagram professional account and Meta app, grant the required permissions, and configure a public HTTPS webhook.',
        ],
    ],
];

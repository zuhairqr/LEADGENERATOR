<?php
namespace App\Services;
use App\Models\IntegrationSetting;
class ProspectingProfile {
    private const PUBLIC_SEARCH_SCOPES = [
        'site:reddit.com/r/forhire', 'site:reddit.com/r/hiring',
        'site:reddit.com/r/jobbit', 'site:reddit.com/r/DesignJobs',
        'site:reddit.com/r/wordpressjobs', 'site:reddit.com/r/remotejs',
        'site:reddit.com/r/web_design', 'site:reddit.com/r/Wordpress',
        'site:reddit.com/r/shopify', 'site:reddit.com/r/SEO',
        'site:reddit.com/r/startups', 'site:reddit.com/r/Entrepreneur',
        'site:reddit.com/r/SaaS', 'site:reddit.com/r/smallbusiness',
        'site:reddit.com/r/SideProject', 'site:reddit.com/r/SomebodyMakeThis',
        'site:reddit.com/r/webdev', 'site:reddit.com/r/woocommerce',
        'site:reddit.com/r/localseo', 'site:reddit.com/r/ecommerce',
        'site:linkedin.com/posts', 'site:news.ycombinator.com',
    ];
    public static function publicCommunityScopes(): array {
        return array_values(array_filter(self::PUBLIC_SEARCH_SCOPES, fn($scope) => str_starts_with($scope, 'site:reddit.com/r/')));
    }
    public const DEFAULT_SKILLS = [
        'Laravel'=>['laravel','livewire','filament'], 'Websites'=>['website','web development','wordpress','shopify','ecommerce','landing page'],
        'AI automation'=>['n8n','workflow automation','ai automation','zapier','make.com'],
        'AI integration'=>['ai integration','openai','chatbot','llm','rag','gpt'],
        'Merchant integration'=>['payment gateway','merchant integration','stripe','paypal','checkout','payment integration'],
    ];
    private const PROJECT_SKILLS = [
        'AI integration and agents' => ['ai integration', 'ai agent', 'ai agents', 'agent development'],
        'OpenAI and ChatGPT' => ['openai', 'openai api', 'chatgpt'],
        'Chatbots and automation' => ['chatbot', 'ai automation', 'workflow automation', 'n8n'],
        'Laravel and PHP' => ['laravel', 'php', 'livewire', 'filament'],
        'SaaS and MVPs' => ['saas', 'saas mvp', 'minimum viable product'],
        'CRM and ERP' => ['crm', 'erp', 'customer relationship management', 'enterprise resource planning'],
        'Custom software and web apps' => ['custom software', 'software development', 'web application', 'web applications', 'web app', 'full stack', 'full-stack'],
        'APIs and integrations' => ['api development', 'api integration', 'third-party integration', 'third-party integrations', 'system integration'],
        'React, Next.js and Node.js' => ['react', 'next.js', 'nextjs', 'node.js', 'nodejs'],
        'Website design and redesign' => ['website design', 'web design', 'website redesign', 'website development', 'web development', 'website rebuild', 'website overhaul'],
        'WordPress and WooCommerce' => ['wordpress', 'woocommerce'],
        'Shopify and ecommerce' => ['shopify', 'ecommerce', 'e-commerce'],
        'SEO and digital marketing' => ['seo', 'search engine optimization', 'search engine optimisation', 'digital marketing'],
        'Maintenance and bug fixes' => ['website maintenance', 'website support', 'bug fix', 'bug fixes', 'technical support', 'post-launch support'],
    ];
    private const PROJECT_SEARCH_PHRASES = [
        'looking for Laravel developer', 'need SaaS developer', 'building a SaaS MVP',
        'looking for AI automation developer', 'need OpenAI API integration', 'looking for AI agent developer',
        'hire full stack developer', 'custom CRM development needed', 'need chatbot for my business',
        'looking for software development agency', 'need API integration', 'website redesign needed',
        'looking for Shopify developer', 'need WordPress developer', 'looking for SEO agency',
    ];

    public function data(): array { return IntegrationSetting::find('pipeline')?->data ?? []; }
    public function skills(): array {
        $profile = $this->data();
        $skills = $profile['skills'] ?? self::DEFAULT_SKILLS;
        return !empty($profile['project_discovery_profile']) ? $skills + self::PROJECT_SKILLS : $skills;
    }
    public function configuredSkills(): array { return $this->data()['skills'] ?? self::DEFAULT_SKILLS; }
    public function filters(): array {
        $d=$this->data(); return ['keywords'=>array_values(array_unique(array_merge(...array_values($this->skills())))),
            'excludedKeywords'=>$d['excluded_keywords'] ?? [], 'maxAgeDays'=>$d['max_age_days'] ?? 30,
            'excludedCountries'=>app(CountryPolicy::class)->excluded(),
            'locationKeywords'=>$d['locations'] ?? []];
    }
    public function query(string $source, array $config, int $count): string {
        $skills=$this->skills(); $names=array_keys($skills); $name=$names[$count % count($names)];
        $keyword=str_replace('"','',$skills[$name][0]);
        $role=match(true){
            preg_match('/\bcrm\b/i',$keyword)===1 => 'CRM implementation consultant',
            preg_match('/\bseo\b/i',$keyword)===1 => 'SEO consultant',
            preg_match('/\b(?:website|web design)\b/i',$keyword)===1 => 'website developer',
            preg_match('/\b(?:automation|n8n|zapier)\b/i',$keyword)===1 => $keyword.' specialist',
            default => $keyword.' developer',
        };
        $intent=['"need a"','"looking for a"','"seeking a"'][intdiv($count,count($names)) % 3];
        $scope=$source==='public-web' ? self::PUBLIC_SEARCH_SCOPES[$count % count(self::PUBLIC_SEARCH_SCOPES)] : $config['search_scope'];
        if (empty($this->data()['project_discovery_profile'])) {
            return $scope.' '.$intent.' "'.$role.'" -course -training -institute -jobs';
        }
        $phrase = self::PROJECT_SEARCH_PHRASES[$count % count(self::PROJECT_SEARCH_PHRASES)];
        return $scope.' ("'.$phrase.'" OR ('.$intent.' "'.$role.'")) -course -training -institute -"hire me" -"open to work"';
    }
    public function aiReady(): bool {
        $d=$this->data(); return ($d['profile_verified'] ?? false) && ($d['ai_consent'] ?? false)
            && trim($d['business_name'] ?? '')!=='' && trim($d['verified_facts'] ?? '')!=='' && ($this->bridges()['ai_ready'] ?? false);
    }
    public function sender(): array { return IntegrationSetting::find('mail')?->data ?? ['provider'=>'smtp','from_email'=>'','from_name'=>'']; }
    public function bridges(): array {
        $path=storage_path('n8n/lead-collector/sales-connection.json');
        $bridges=is_file($path) ? (json_decode(file_get_contents($path),true) ?: []) : [];
        if(array_key_exists('gmail_installed',$bridges)||array_key_exists('inbox_installed',$bridges)){
            $h=IntegrationSetting::find('mailbox_health')?->data??[];$mail=$this->sender();$authorized=!empty($h['connected'])&&!empty($h['sender_matches'])&&($h['credential_name']??'')===($mail['gmail_credential_name']??'')&&($bridges['gmail_credential_name']??'')===($mail['gmail_credential_name']??'');
            $bridges['gmail_ready']=!empty($bridges['gmail_installed'])&&$authorized;$bridges['inbox_ready']=!empty($bridges['inbox_installed'])&&$authorized;
        }
        return $bridges;
    }
}

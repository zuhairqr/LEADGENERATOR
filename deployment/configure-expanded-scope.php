<?php
// Apply the owner's explicit targeting request. No credentials are generated or printed.
require __DIR__.'/../vendor/autoload.php';$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('local'))throw new RuntimeException('This setup is scoped to the authorized local project.');
$skills=[
 'Websites'=>['website design','web development','redesign','wordpress','shopify','woocommerce','squarespace','wix','landing page'],
 'Web frameworks'=>['laravel','php','react','next.js','node.js','vue.js','livewire','filament'],
 'Web applications and SaaS'=>['custom web application','saas','web app','dashboard'],
 'AI services'=>['ai integration','ai agent','ai automation','chatbot','openai','llm','gpt','rag'],
 'Workflow automation'=>['n8n','zapier','make.com','workflow automation','business automation'],
 'Business systems'=>['crm','erp','business management','system integration'],
 'Ecommerce'=>['ecommerce','e-commerce','checkout','store development','ecommerce integration'],
 'Mobile applications'=>['ios','android','mobile app','mobile application'],
 'APIs'=>['api development','api integration','third-party integration'],
 'SEO'=>['seo','local seo','technical seo','search engine optimization'],
 'Digital marketing'=>['digital marketing','google ads','social media marketing'],
 'UI and UX'=>['ui/ux','ui design','ux design','figma','prototyping'],
 'Maintenance and security'=>['website maintenance','bug fixing','website security','website support'],
 'Database services'=>['database development','database migration','data migration'],
 'Cloud and DevOps'=>['cloud hosting','aws','devops'],
 'Payments and booking'=>['payment gateway','stripe','paypal','booking system','merchant integration','subscription'],
 'Performance'=>['speed optimization','website performance','performance optimization'],
 'Software modernization'=>['software development','system modernization','legacy modernization','IT services'],
];
$record=App\Models\IntegrationSetting::find('pipeline');$data=$record?->data??[];$data['skills']=$skills;$data['locations']=[];
$addition="\n\nAdditional services expressly provided by the operator on 3 November 2026 (services offered; no extra experience/results are implied):\nWebsite design/development/redesign; WordPress, Shopify, WooCommerce, Squarespace, Wix; Laravel, PHP, React, Next.js, Node.js, Vue.js; custom web applications and SaaS; AI integrations/agents/automation, chatbots, OpenAI API and LLM integration; CRM, ERP and business systems; eCommerce; iOS/Android mobile development; APIs and third-party integrations; SEO/local SEO/technical SEO; digital marketing, Google Ads and social media marketing; UI/UX, Figma and prototyping; maintenance, bug fixing and security; database development/migration; cloud hosting, AWS and DevOps; payment/booking integrations; speed optimization and system modernization. Never invent client results, certifications, years of experience or unsupported portfolio technology claims.";
if(!str_contains($data['verified_facts']??'','Additional services expressly provided by the operator on 3 November 2026'))$data['verified_facts']=($data['verified_facts']??'').$addition;
App\Models\IntegrationSetting::updateOrCreate(['key'=>'pipeline'],['data'=>$data]);
App\Models\IntegrationSetting::updateOrCreate(['key'=>'business_discovery'],['data'=>['niches'=>['All businesses'],'locations'=>[],'excluded_countries'=>['IN','PK'],'require_country_review'=>true,'enabled'=>true,'auto_audit'=>true]]);
$path=app()->environmentFilePath();if(is_link($path)||!is_file($path))throw new RuntimeException('Private configuration file is unavailable.');$text=file_get_contents($path);$line='LEAD_BUSINESS_WEB_APPROVED=true';$text=preg_match('/^LEAD_BUSINESS_WEB_APPROVED=.*$/m',$text)?preg_replace('/^LEAD_BUSINESS_WEB_APPROVED=.*$/m',$line,$text):rtrim($text)."\n".$line."\n";if(file_put_contents($path,$text,LOCK_EX)!==strlen($text))throw new RuntimeException('Configuration write failed.');Illuminate\Support\Facades\Artisan::call('config:clear');
echo "Saved worldwide/all-business targeting, India/Pakistan exclusions, country-review requirement and 18 requested service groups. Existing credentials and experience claims were preserved.\n";

<?php
namespace App\Services;

/** Deterministic relevance signals; these never establish buyer interest or permission. */
class BusinessProspectQuality
{
    private const SEGMENTS = ['dental clinics','plumbing contractors','accounting firms','independent hotels','veterinary clinics','local restaurants','property management companies','physiotherapy clinics','furniture manufacturers','landscaping companies','beauty salons','specialist retailers','law firms','fitness studios','catering companies','automotive repair shops','tour operators','architectural practices'];
    private const NON_PROSPECTS = ['google.com','microsoft.com','microsoftonline.com','steampowered.com','steamcommunity.com','apple.com','amazon.com','wikipedia.org','wiktionary.org','thefreedictionary.com','dictionary.com','merriam-webster.com','bing.com','yahoo.com','facebook.com','instagram.com','linkedin.com','youtube.com','reddit.com','tiktok.com','pinterest.com','x.com','twitter.com','indeed.com','glassdoor.com','yelp.com','tripadvisor.com','clutch.co','trustpilot.com','github.com','stackoverflow.com','wordpress.org','wix.com','shopify.com','squarespace.com','mozilla.org','thewindowsclub.com','kotaku.com','calendly.com','setmore.com','simplybook.me','acuityscheduling.com'];
    private const JOB_PLATFORMS = ['upwork.com','freelancer.com','freelancer.co.uk','freelancer.com.au','freelancer.ca','fiverr.com','peopleperhour.com','remotive.com','remoteok.com','arbeitnow.com','jobicy.com','weworkremotely.com','himalayas.app'];

    public static function blockedHost(string $host): bool
    {
        $host=strtolower(rtrim($host,'.'));
        foreach([...self::NON_PROSPECTS, ...self::JOB_PLATFORMS] as $parent) if($host===$parent||str_ends_with($host,'.'.$parent)) return true;
        return (bool)preg_match('/^(?:docs|developer|developers|learn|support|help|apps|status|community|forums?|careers|jobs)\./i',$host);
    }

    public function plan(string $niche,string $location,int $iteration,array $skills,?string $requestedMode=null): array
    {
        $all=in_array(mb_strtolower(trim($niche)),['all businesses','all','*'],true);
        $target=$all?self::SEGMENTS[$iteration%count(self::SEGMENTS)]:trim($niche);
        $groups=array_values(array_filter($skills,fn($terms)=>is_array($terms)&&count($terms)));
        $service=$groups?($groups[intdiv($iteration,3)%count($groups)][0]??'website redesign'):'website redesign';
        $clean=fn($s)=>trim(preg_replace('/["\r\n\\\\]+/u',' ',mb_substr($s,0,100)));
        $mode=$requestedMode??($iteration%4===3?'service-request':'business');
        if(!in_array($mode,['business','service-request'],true))throw new \InvalidArgumentException('Invalid discovery mode');
        $queryTarget=preg_replace_callback('/\b(clinics|contractors|firms|hotels|restaurants|manufacturers|salons|retailers|studios|operators|practices)\b/u',fn($m)=>substr($m[0],0,-1),$target);
        $queryTarget=str_replace('companies','company',$queryTarget);
        $query='"'.$clean($queryTarget).'" '.($location?'"'.$clean($location).'" ':'');
        $query.=$mode==='service-request'?'"'.$clean($service).'" ("looking for" OR "request for proposal" OR "need a new")':'"contact us" -"software for" -"marketing for" -"answering service"';
        $query.=' -India -Pakistan -site:.in -site:.pk -site:google.com -site:microsoft.com -site:steampowered.com -site:wikipedia.org -site:facebook.com -site:linkedin.com -site:indeed.com';
        return ['query'=>$query,'target'=>$target,'mode'=>$mode,'service'=>$service];
    }

    public function assess(array $row,string $target,array $skills,string $mode='business'): array
    {
        $fail=fn($code)=>['accepted'=>false,'score'=>0,'signals'=>[],'buyer_request'=>false,'service_matches'=>[],'reason'=>$code];
        try {$url=PublicWebClient::url($row['url']??'');} catch(\Throwable $e) {return $fail('UNSAFE_URL');}
        $host=strtolower(parse_url($url,PHP_URL_HOST));
        if(self::blockedHost($host)) return $fail('PLATFORM_DIRECTORY_OR_CONTENT_HOST');
        if(preg_match('/\.(?:in|pk)$/i',$host))return $fail('EXCLUDED_COUNTRY_DOMAIN');
        $title=html_entity_decode(strip_tags($row['title']??''),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $snippet=html_entity_decode(strip_tags($row['content']??''),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $text=$title.' '.$snippet;
        if(preg_match('/\b(?:India|Pakistan)\b/i',$text))return $fail('EXCLUDED_COUNTRY_MENTION');
        $path=rawurldecode(parse_url($url,PHP_URL_PATH)??'/');
        if(preg_match('~/(?:wiki|dictionary|definition|downloads?|apps?|games?|news|blog|articles?|careers|jobs|search)(?:/|$)~i',$path))return $fail('CONTENT_OR_LISTING_PAGE');
        $buyer=(bool)preg_match('/\b(?:we(?:\s+are|\x{2019}re|\x27re)?\s+(?:looking for|seeking)|request\s+for\s+proposals?|seeking\s+(?:an?\s+)?(?:agency|developer|designer)|need(?:s)?\s+(?:an?\s+)?(?:new\s+)?(?:website|redesign|chatbot|automation|app)|looking\s+for\s+[^.!?\r\n]{0,60}\b(?:developer|designer|agency|website|automation|integration|software|app|seo))\b/iu',$text);
        $vendorTarget=(bool)preg_match('/software|saas|agenc(?:y|ies)|technology|web design|it services|platform/iu',$target);
        if(preg_match('/^\s*\d+\s+(?:best|top|ways)\b|\b(?:how to|what is|complete guide|ultimate guide|buying guide|tax season guide|a guide to|comparison|compared)\b/iu',$title))return $fail('EDITORIAL_OR_COMPARISON_RESULT');
        if(!$buyer&&!$vendorTarget&&preg_match('/\b(?:web design agency|website design company|software development services|we (?:offer|provide) (?:web|seo|software)|digital marketing agency)\b/i',$text))return $fail('SERVICE_SELLER_NOT_BUYER');
        if(!$buyer&&!$vendorTarget&&preg_match('/\b(?:software|platforms?|apps?|answering services?|marketing|scheduling|booking systems?|receptionists?|lead generation)\b.{0,55}\bfor\b|\b(?:answering services?|(?:ai|virtual) receptionists?|call answering|appointment (?:booking|scheduling) software)\b/iu',$title))return $fail('INDUSTRY_VENDOR_NOT_TARGET_BUSINESS');
        $targetWords=preg_split('/[^\pL\pN]+/u',mb_strtolower($target),-1,PREG_SPLIT_NO_EMPTY);
        $aliases=['dental'=>['dental','dentist','dentistry'],'plumbing'=>['plumbing','plumber'],'accounting'=>['accounting','accountant'],'veterinary'=>['veterinary','veterinarian','vet'],'automotive'=>['automotive','mechanic','auto repair'],'architectural'=>['architectural','architect','architecture'],'physiotherapy'=>['physiotherapy','physiotherapist','physical therapy']];
        $generic=['all','business','businesses','company','companies','services','local','independent','specialist'];
        if(count($targetWords)>1)$generic=array_merge($generic,['clinics','firms','contractors','shops','practices','and']);
        $topic=[];foreach($targetWords as $word)if(mb_strlen($word)>=3&&!in_array($word,$generic,true))foreach($aliases[$word]??[$word] as $term)$topic[]=$term;
        $topicMatch=false;foreach($topic as $term){$stem=str_ends_with($term,'s')&&!str_ends_with($term,'ss')?mb_substr($term,0,-1):$term;if(preg_match('/(?<![\pL\pN])'.preg_quote($stem,'/').'s?(?![\pL\pN])/iu',$text)){$topicMatch=true;break;}}
        $contact=(bool)preg_match('/\b(?:contact|appointments?|book(?:ing)?|call us|visit us|our (?:team|practice|services)|request a quote|family.owned|serving|patients?|customers?)\b/i',$text.' '.$path);
        $matches=$this->serviceMatches($text,$skills,$buyer);
        if($mode==='service-request'&&(!$buyer||!$matches))return $fail('NO_BUYER_SERVICE_EVIDENCE');
        $parts=preg_split('/\s+[|\x{2013}\x{2014}]\s+|\s+-\s+/u',trim($title));
        $name='';foreach($parts as $part){$part=trim($part);if(mb_strlen($part)>=3&&!preg_match('/^(?:home|contact(?: us)?|about(?: us)?|welcome|official website|services)$/i',$part)&&!preg_match('/^(?:book\b|request\b|schedule\b|get in touch\b)/i',$part)&&strcasecmp($part,$host)!==0){$name=$part;break;}}
        $signals=[];$score=0;
        if($topicMatch){$score+=35;$signals[]='TARGET_BUSINESS_TYPE';}
        if($contact){$score+=20;$signals[]='CONTACT_OR_BOOKING';}
        if($name!==''){$score+=10;$signals[]='NAMED_SEARCH_RESULT';}
        if($buyer&&$matches){$score+=35;$signals[]='BUYER_REQUEST_WITH_SAVED_SERVICE';}
        if($score<55||(!$topicMatch&&!$buyer))return $fail('INSUFFICIENT_BUSINESS_RELEVANCE');
        return ['accepted'=>true,'score'=>min(95,$score),'signals'=>$signals,'buyer_request'=>$buyer&&count($matches)>0,'service_matches'=>$buyer?$matches:[],'reason'=>null,'name'=>mb_substr($name?:$host,0,170),'url'=>$url,'host'=>preg_replace('/^www\./','',$host),'snippet'=>mb_substr(trim($snippet),0,2000),'target'=>$target,'mode'=>$mode];
    }

    public function serviceMatches(string $text,array $skills,bool $allowGeneric=false): array
    {
        $matches=[];$generic=['web','website','websites','software','development','design','services','business','store','app','ai'];
        foreach($skills as $name=>$terms)foreach($terms as $term){$term=trim($term);if(mb_strlen($term)<3||(!$allowGeneric&&in_array(mb_strtolower($term),$generic,true)))continue;
            if(preg_match('/(?<![\pL\pN])'.preg_quote($term,'/').'(?![\pL\pN])/iu',$text)){$matches[]=$name;break;}}
        return array_values(array_unique($matches));
    }

    public function auditFit(array $findings,array $skills,array $discovery=[]): array
    {
        $rules=['TITLE_MISSING'=>'seo|metadata|website|wordpress|web development','TITLE_LONG'=>'seo|metadata|website|wordpress','META_DESCRIPTION_MISSING'=>'seo|metadata|website|wordpress|web development','VIEWPORT_MISSING'=>'responsive|ui.?ux|website|web development|frontend','H1_MISSING'=>'seo|content|website|web development','IMAGE_ALT_MISSING'=>'accessibility|seo|ui.?ux|website','HTTP_PAGE'=>'security|hosting|devops|maintenance|website','LARGE_HTML'=>'speed|performance|optimization|website'];
        $matched=[];$points=0;$relevant=0;
        foreach($findings as $finding){$rule=$rules[$finding['code']]??null;if(!$rule)continue;$groups=[];
            foreach($skills as $name=>$terms)if(preg_match('/'.$rule.'/iu',$name.' '.implode(' ',$terms)))$groups[]=$name;
            if(!$groups)continue;$relevant++;$matched=array_merge($matched,$groups);$points+=['high'=>25,'medium'=>10,'low'=>3][$finding['severity']]??0;
        }
        $request=!empty($discovery['buyer_request'])&&!empty($discovery['service_matches']);
        $score=$relevant?min(95,20+$points+($request?25:0)):($request?55:15);
        return ['score'=>$score,'services'=>array_values(array_unique($matched)),'reason'=>$relevant.' static HTML observations match saved services. '.($request?'Search snippet includes an unverified service request.':'Buyer interest and budget are not established.')];
    }
}

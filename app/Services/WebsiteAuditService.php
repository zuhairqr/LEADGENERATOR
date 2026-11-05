<?php
namespace App\Services;
use App\Models\{BusinessRecord,Lead,WebsiteAudit,OutreachEvent};
use Illuminate\Support\Facades\{Cache,DB};
class WebsiteAuditService {
 public function __construct(private PublicWebClient $web){}
 private function xpath(string $html): \DOMXPath {
  $dom = new \DOMDocument();
  $previous = libxml_use_internal_errors(true);
  try {
   $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
  } finally {
   libxml_clear_errors();
   libxml_use_internal_errors($previous);
  }
  return new \DOMXPath($dom);
 }

 private function publicEmails(\DOMXPath $xpath, bool $includePublishedText = false): array {
  $candidates = [];
  foreach ($xpath->query('//a[@href and not(ancestor-or-self::*[@hidden or @aria-hidden="true" or self::template])]') as $link) {
   $href = trim($link->getAttribute('href'));
   if (strncasecmp($href, 'mailto:', 7) === 0) {
    $candidates[] = rawurldecode(explode('?', substr($href, 7), 2)[0]);
   }
  }
  if ($includePublishedText) {
   foreach ($xpath->query('//body//text()[not(ancestor::*[self::script or self::style or self::noscript or self::template or self::svg or self::pre or self::code or @hidden or @aria-hidden="true"])]') as $node) {
    preg_match_all('/(?<![\w.+-])[a-z0-9._%+\-]{1,64}@[a-z0-9.-]+\.[a-z]{2,63}(?![\w-]|\.[a-z0-9])/i', $node->nodeValue, $matches);
    array_push($candidates, ...$matches[0]);
   }
  }
  $emails = [];
  foreach ($candidates as $candidate) {
   $email = strtolower(trim($candidate));
   if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
   [$mailbox, $domain] = explode('@', $email, 2);
   if (preg_match('/^(?:no[._-]?reply|do[._-]?not[._-]?reply)$/', $mailbox)
       || in_array($domain, ['example.com', 'example.org', 'example.net'], true)) continue;
   $emails[$email] = $email;
   if (count($emails) === 15) break;
  }
  return array_values($emails);
 }

 private function websiteContacts(array $home, array $evidence): array {
  if (BusinessProspectQuality::blockedHost((string) parse_url($home['url'], PHP_URL_HOST))) return [];
  $sources = array_fill_keys($evidence['public_emails'], $home['url']);
  $contactPage = $evidence['contact_page_urls'][0] ?? null;
  if ($sources || !$contactPage) return $sources;
  try {
   $page = $this->web->fetch($contactPage, 384 * 1024, sameSiteRedirects: true);
   if ($page['status'] < 200 || $page['status'] >= 300 || !PublicWebClient::sameSite($home['url'], $page['url'])
       || !str_contains(strtolower($page['headers']['content-type'] ?? ''), 'html')
       || strtolower($page['headers']['cf-mitigated'] ?? '') === 'challenge'
       || preg_match('~<title[^>]*>\s*(?:just a moment|access denied|attention required|checking your browser)~i', $page['body'])) {
    return [];
   }
   return array_fill_keys($this->publicEmails($this->xpath($page['body']), true), $page['url']);
  } catch (\RuntimeException) {
   return [];
  }
 }

 public function analyze(string $html,array $response):array {
  $x=$this->xpath($html);
  $text=fn($query)=>trim(preg_replace('/\s+/u',' ',$x->query($query)?->item(0)?->textContent??''));
  $title=$text('//title');$description=$x->query('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content')->item(0)?->nodeValue??'';
  $viewport=$x->query('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="viewport"]/@content')->item(0)?->nodeValue??'';
  $h1=$x->query('//h1')->length;$images=$x->query('//img')->length;$missingAlt=$x->query('//img[not(@alt)]')->length;
  $findings=[];$add=function($code,$severity,$observation,$offer)use(&$findings){$findings[]=['code'=>$code,'severity'=>$severity,'observation'=>$observation,'possible_service'=>$offer];};
  if(!$title)$add('TITLE_MISSING','medium','No HTML title element was found.','SEO metadata review');elseif(mb_strlen($title)>70)$add('TITLE_LONG','low','The title has '.mb_strlen($title).' characters. This alone does not establish a ranking issue.','Review search-result titles');
  if(!$description)$add('META_DESCRIPTION_MISSING','medium','No meta description was found in the fetched HTML.','Metadata and content review');
  if(!$viewport)$add('VIEWPORT_MISSING','medium','No viewport meta tag was found. Mobile layout has not been visually tested.','Responsive layout review');
  if($h1===0)$add('H1_MISSING','medium','No H1 heading was found in the fetched HTML.','Page content hierarchy review');
  if($missingAlt)$add('IMAGE_ALT_MISSING','medium',$missingAlt.' of '.$images.' image elements lack an alt attribute; decorative images may correctly use empty alt.','Accessibility review');
  if(parse_url($response['url'],PHP_URL_SCHEME)!=='https')$add('HTTP_PAGE','high','The final page used HTTP rather than HTTPS.','HTTPS configuration review');
  if(strlen($html)>512000)$add('LARGE_HTML','low','Fetched HTML is larger than 500 KB. Assets and browser performance were not measured.','Investigate page weight');
  $links = [];
  $contactPages = [];
  foreach ($x->query('//a[@href]') as $a) {
   $href = trim($a->getAttribute('href'));
   $label = trim($a->textContent);
   if ($href === '' || str_starts_with($href, '#') || preg_match('~^(?!https?:)[a-z][a-z0-9+.-]*:~i', $href)) continue;
   $contactLink = preg_match('/\b(?:contact|get in touch|reach us)\b/i', $label)
       || preg_match('~(?:^|/)(?:contact|contact-us|get-in-touch)(?:[./?#]|$)~i', $href);
   if (!$contactLink && !preg_match('/about|services/i', $label.' '.$href)) continue;
   try {
    $target = PublicWebClient::resolve($response['url'], $href);
   } catch (\RuntimeException) {
    continue;
   }
   if ($target === $response['url'] || !PublicWebClient::sameSite($target, $response['url'])) continue;
   $links[$target] = $target;
   if ($contactLink) $contactPages[$target] = $target;
  }
  $countries=[];$walk=function($node)use(&$walk,&$countries){if(!is_array($node))return;if(isset($node['addressCountry'])){$v=is_array($node['addressCountry'])?($node['addressCountry']['name']??$node['addressCountry']['@id']??''):$node['addressCountry'];if(is_string($v)&&($code=CountryPolicy::normalize($v)))$countries[$code]=$code;}foreach($node as $child)if(is_array($child))$walk($child);};foreach($x->query('//script[@type="application/ld+json"]') as $script)$walk(json_decode($script->textContent,true));
  $status=$response['status'];if($status>=400)$add('HTTP_ERROR','high','The fetched page returned HTTP '.$status.'. This may be temporary or bot-specific.','Manual availability review');
  return ['score'=>max(0,100-array_sum(array_map(fn($f)=>['high'=>25,'medium'=>12,'low'=>5][$f['severity']],$findings))),
   'findings'=>$findings,'evidence'=>['title'=>mb_substr($title,0,300),'meta_description'=>mb_substr($description,0,500),'viewport'=>mb_substr($viewport,0,300),'h1_count'=>$h1,'image_count'=>$images,'images_missing_alt'=>$missingAlt,'html_bytes'=>strlen($html),'html_sha256'=>hash('sha256',$html),'public_emails'=>$this->publicEmails($x),'contact_links'=>array_slice(array_values($links),0,5),'contact_page_urls'=>array_slice(array_values($contactPages),0,3),
    'declared_countries'=>array_values($countries),'scope'=>'Static HTML observation only; no JavaScript rendering, vulnerability scan, Google rankings or Lighthouse/Core Web Vitals measurement.','checked_at'=>now()->toIso8601String()]];
 }
 public function run(Lead $lead):WebsiteAudit {
  if($lead->kind==='business' && !$lead->business?->website)throw new \RuntimeException('BUSINESS_WEBSITE_REQUIRED');
  $url=$lead->business?->website??$lead->url;$url=PublicWebClient::url($url);$lock=Cache::lock('audit:'.$lead->id,90);if(!$lock->get())throw new \RuntimeException('AUDIT_ALREADY_RUNNING');
  try{
   $audit=WebsiteAudit::create(['lead_id'=>$lead->id,'requested_url'=>$url,'status'=>'running']);
   try{$r=$this->web->fetch($url);if(!str_contains(strtolower($r['headers']['content-type']??''),'html'))throw new \RuntimeException('NOT_HTML');
    if($r['status']<200||$r['status']>=300||strtolower($r['headers']['cf-mitigated']??'')==='challenge'||preg_match('~<title[^>]*>\s*(?:just a moment|access denied|attention required|checking your browser)~i',$r['body'])){$audit->update(['status'=>'limited','final_url'=>$r['url'],'http_status'=>$r['status'],'elapsed_ms'=>$r['elapsed_ms'],'error_code'=>'CONTENT_UNAVAILABLE_OR_CHALLENGED','evidence'=>['scope'=>'Site content could not be reviewed. HTTP/bot challenge responses do not establish SEO, accessibility or performance defects.','checked_at'=>now()->toIso8601String()],'findings'=>[]]);return $audit->fresh();}
    $result=$this->analyze($r['body'],$r);
    $businessWebsite = $lead->business?->website;
    $contactSources = $businessWebsite ? $this->websiteContacts($r, $result['evidence']) : [];
    if ($businessWebsite) {
     $result['evidence']['public_emails'] = array_keys($contactSources);
     $result['evidence']['public_email_sources'] = $contactSources;
    }
    $audit->update(['status'=>'completed','final_url'=>$r['url'],'http_status'=>$r['status'],'elapsed_ms'=>$r['elapsed_ms'],'score'=>$result['score'],'evidence'=>$result['evidence'],'findings'=>$result['findings']]);
    if($lead->kind==='business'){
     $fit=app(BusinessProspectQuality::class)->auditFit($result['findings'],app(ProspectingProfile::class)->skills(),$lead->business?->discovery_evidence??[]);
     if($lead->source==='business-web'&&BusinessProspectQuality::blockedHost(parse_url($url,PHP_URL_HOST))){$fit=['score'=>0,'services'=>[],'reason'=>'Platform/content host, not a direct business prospect.'];}
     $lead->match_score=$fit['score'];$lead->match_reason=$fit['reason'];$lead->matched_services=$fit['services'];$lead->save();
    }
    if($businessWebsite) DB::transaction(function () use ($lead, $businessWebsite, $contactSources, $result, $r) {
     $business = BusinessRecord::whereKey($lead->business_record_id)->lockForUpdate()->first();
     if (!$business || $business->website !== $businessWebsite) return;
     $contacts=$business->contacts??[];
     foreach ($contactSources as $email => $source) {
      $contacts[$email] ??= ['email'=>$email, 'source'=>$source, 'verified'=>false];
     }
     $business->contacts=$contacts;
     $countries=$result['evidence']['declared_countries'];$excluded=array_intersect($countries,app(CountryPolicy::class)->excluded());
     if($excluded || (!$business->country_code && count($countries)===1)){$code=$excluded?array_values($excluded)[0]:$countries[0];$business->country_code=$code;$business->geo_status=app(CountryPolicy::class)->status($code);$business->country_evidence='Website JSON-LD addressCountry: '.$code.' at '.$r['url'];}
     $business->save();if($business->geo_status==='excluded'){$lead->status='rejected';$lead->save();}
    }, 3);
    OutreachEvent::create(['lead_id'=>$lead->id,'event'=>'website_audited','details'=>['audit_id'=>$audit->id,'findings'=>count($result['findings'])]]);
   }catch(\Throwable $e){$code=in_array($e->getMessage(),['PRIVATE_ADDRESS_BLOCKED','DNS_LOOKUP_FAILED','PUBLIC_FETCH_FAILED','RESPONSE_TOO_LARGE','NOT_HTML','TOO_MANY_REDIRECTS','REMOTE_ADDRESS_CHANGED'],true)?$e->getMessage():'AUDIT_FAILED';$audit->update(['status'=>'failed','error_code'=>$code]);}
   return $audit->fresh();
  }finally{$lock->release();}
 }
}

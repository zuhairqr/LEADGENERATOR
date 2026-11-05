<?php
namespace App\Services;

/** Bounded public web fetches. Every redirect is revalidated and DNS is pinned per request. */
class PublicWebClient
{
 public static function publicAddress(string $ip):bool {
  if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return false;
  if(str_contains($ip,':')){ $p=inet_pton($ip);return $p!==false && (ord($p[0])&0xe0)===0x20 && !preg_match('/^(?:2001:db8|2002:|2001:0:)/i',$ip); }
  $n=ip2long($ip);foreach([['100.64.0.0',10],['192.0.0.0',24],['192.0.2.0',24],['198.18.0.0',15],['198.51.100.0',24],['203.0.113.0',24],['224.0.0.0',4]] as [$net,$bits])if(($n&(-1<<(32-$bits)))===(ip2long($net)&(-1<<(32-$bits))))return false;
  return true;
 }
 public static function url(string $url):string {
  if(strlen($url)>2048 || preg_match('/[\x00-\x20\x7f\\\\]/',$url))throw new \RuntimeException('UNSAFE_URL');
  $p=parse_url($url);$scheme=strtolower($p['scheme']??'');$host=strtolower(rtrim($p['host']??'','.'));
  if(!in_array($scheme,['http','https'],true)||!$host||isset($p['user'])||isset($p['pass'])||isset($p['port'])&&!in_array($p['port'],[80,443],true))throw new \RuntimeException('UNSAFE_URL');
  if(preg_match('/[^a-z0-9.\-\[\]:]/i',$host) || !str_contains($host,'.') || preg_match('/(?:^|\.)(?:localhost|local|internal|test|invalid|onion)$/i',$host))throw new \RuntimeException('UNSAFE_HOST');
  $literal=trim($host,'[]');if(filter_var($literal,FILTER_VALIDATE_IP)&&!self::publicAddress($literal))throw new \RuntimeException('PRIVATE_ADDRESS_BLOCKED');
  if(preg_match('/^[0-9.]+$/',$host)&&!filter_var($host,FILTER_VALIDATE_IP))throw new \RuntimeException('UNSAFE_HOST');
  return $scheme.'://'.$host.(isset($p['port'])?':'.$p['port']:'').($p['path']??'/').(isset($p['query'])?'?'.$p['query']:'');
 }
 protected function addresses(string $host):array {
  $host=trim($host,'[]');if(filter_var($host,FILTER_VALIDATE_IP))return [$host];
  $records=@dns_get_record($host,DNS_A|DNS_AAAA);$ips=[];foreach($records?:[] as $r)if(isset($r['ip'])||isset($r['ipv6']))$ips[]=$r['ip']??$r['ipv6'];return array_values(array_unique($ips));
 }
 public static function resolve(string $base,string $relative):string {
  $relative=trim(html_entity_decode($relative,ENT_QUOTES|ENT_HTML5));if(preg_match('~^https?://~i',$relative))return self::url($relative);
  $b=parse_url($base);$origin=$b['scheme'].'://'.$b['host'].(isset($b['port'])?':'.$b['port']:'');if(str_starts_with($relative,'//'))return self::url($b['scheme'].':'.$relative);
  if(str_starts_with($relative,'?'))return self::url($origin.($b['path']??'/').$relative);
  $path=str_starts_with($relative,'/')?$relative:dirname($b['path']??'/').'/'.$relative;$parts=[];foreach(explode('/',$path) as $part){if($part==='..')array_pop($parts);elseif($part!==''&&$part!=='.')$parts[]=$part;}return self::url($origin.'/'.implode('/',$parts));
 }
 public static function sameSite(string $left, string $right): bool {
  $host = fn ($url) => preg_replace('/^www\./i', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
  return $host($left) !== '' && $host($left) === $host($right);
 }
 public function fetch(string $url,int $maxBytes=1048576,array $headers=[],string $method='GET',?string $body=null,bool $sameSiteRedirects=false):array {
  $url=self::url($url);$started=microtime(true);
  for($hop=0;$hop<4;$hop++){
   $p=parse_url($url);$ips=$this->addresses($p['host']);if(!$ips)throw new \RuntimeException('DNS_LOOKUP_FAILED');foreach($ips as $ip)if(!self::publicAddress($ip))throw new \RuntimeException('PRIVATE_ADDRESS_BLOCKED');
   $port=$p['port']??($p['scheme']==='https'?443:80);$address=str_contains($ips[0],':')?'['.$ips[0].']':$ips[0];$content='';$responseHeaders=[];
   $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
    CURLOPT_RESOLVE=>[$p['host'].':'.$port.':'.$address],CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'LeadGenerator-Audit/1.0 (bounded public-page review)',CURLOPT_HTTPHEADER=>array_merge(['Accept: text/html,application/json;q=0.9,text/plain;q=0.8'],$headers),
    CURLOPT_WRITEFUNCTION=>function($c,$data)use(&$content,$maxBytes){if(strlen($content)+strlen($data)>$maxBytes)return 0;$content.=$data;return strlen($data);},
    CURLOPT_HEADERFUNCTION=>function($c,$line)use(&$responseHeaders){if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$responseHeaders[strtolower(trim($k))]=trim($v);}return strlen($line);}]);
   if($method!=='GET'){curl_setopt($ch,CURLOPT_CUSTOMREQUEST,$method);if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$body);}
   $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$connected=(string)curl_getinfo($ch,CURLINFO_PRIMARY_IP);$errno=curl_errno($ch);curl_close($ch);
   if($ok===false)throw new \RuntimeException($errno===CURLE_WRITE_ERROR?'RESPONSE_TOO_LARGE':'PUBLIC_FETCH_FAILED');
   if(!self::publicAddress($connected)||!in_array($connected,$ips,true))throw new \RuntimeException('REMOTE_ADDRESS_CHANGED');
   if(in_array($status,[301,302,303,307,308],true)&&isset($responseHeaders['location'])){
    // Never forward authorization or message bodies to a redirected connector endpoint.
    if($method!=='GET'||$headers)throw new \RuntimeException('CONNECTOR_REDIRECT_BLOCKED');
    $next=self::resolve($url,$responseHeaders['location']);
    if($sameSiteRedirects&&!self::sameSite($url,$next))throw new \RuntimeException('CONTACT_REDIRECT_BLOCKED');
    $url=$next;continue;
   }
   return ['url'=>$url,'status'=>$status,'headers'=>$responseHeaders,'body'=>$content,'elapsed_ms'=>(int)round((microtime(true)-$started)*1000)];
  }throw new \RuntimeException('TOO_MANY_REDIRECTS');
 }
}

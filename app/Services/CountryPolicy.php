<?php
namespace App\Services;
use App\Models\{Lead,IntegrationSetting};
class CountryPolicy {
 public const REQUIRED_EXCLUSIONS = ['IN', 'PK'];

 public function excluded(): array
 {
     $configured = IntegrationSetting::find('business_discovery')?->data['excluded_countries'] ?? [];
     return array_values(array_unique([...self::REQUIRED_EXCLUSIONS, ...$configured]));
 }
 public static function normalize(?string $value):?string {
  $v=strtoupper(trim($value??''));$aliases=['INDIA'=>'IN','PAKISTAN'=>'PK','UNITED STATES'=>'US','USA'=>'US','UNITED STATES OF AMERICA'=>'US','UNITED KINGDOM'=>'GB','UK'=>'GB','GREAT BRITAIN'=>'GB','UAE'=>'AE','UNITED ARAB EMIRATES'=>'AE','CANADA'=>'CA','AUSTRALIA'=>'AU','GERMANY'=>'DE','FRANCE'=>'FR','NEW ZEALAND'=>'NZ','SAUDI ARABIA'=>'SA'];$v=$aliases[$v]??$v;
  $valid=explode(' ','AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW');return in_array($v,$valid,true)?$v:null;
 }
 public function allowed(Lead $lead):bool {
  if($lead->kind==='business')return $lead->business && $lead->business->geo_status==='allowed' && $lead->business->country_code && !in_array($lead->business->country_code,$this->excluded(),true);
  if(IntegrationSetting::find('business_discovery')?->data['require_country_review']??false)return $lead->country_reviewed_at && $lead->country_code && !in_array($lead->country_code,$this->excluded(),true);
  return !$lead->country_code||!in_array($lead->country_code,$this->excluded(),true);
 }
 public function status(?string $code):string{return !$code?'unknown':(in_array($code,$this->excluded(),true)?'excluded':'allowed');}
 public function scope(\Illuminate\Database\Eloquent\Builder $query):\Illuminate\Database\Eloquent\Builder {
  $require=IntegrationSetting::find('business_discovery')?->data['require_country_review']??false;$excluded=$this->excluded();
  return $query->where(function($q)use($require,$excluded){$q->where(function($r)use($require,$excluded){$r->where('kind','!=','business');if($require)$r->whereNotNull('country_reviewed_at')->whereNotNull('country_code')->whereNotIn('country_code',$excluded);else $r->where(fn($s)=>$s->whereNull('country_code')->orWhereNotIn('country_code',$excluded));})->orWhereHas('business',fn($r)=>$r->where('geo_status','allowed')->whereNotNull('country_code')->whereNotIn('country_code',$excluded));});
 }
}

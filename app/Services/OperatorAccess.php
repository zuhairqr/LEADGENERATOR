<?php
namespace App\Services;
use Illuminate\Http\Request;
class OperatorAccess {
 public function authorize(Request $r,string $role):void {
  if(in_array($role,['owner','admin'],true))return;
  $name=(string)$r->route()?->getName();$path=$r->path();
  if(str_starts_with($path,'settings/')||str_starts_with($path,'team')||str_starts_with($path,'connectors'))abort(403,'Administrator access required.');
  if($r->isMethod('GET')||$r->isMethod('HEAD')||$name==='logout')return;
  if($role==='viewer')abort(403,'Read-only account.');
  if(str_ends_with($name,'.approve')||str_ends_with($name,'.resolve')||in_array($name,['discovery.settings','inbox.connect','inbox.verify'],true))abort(403,'Sending approval or settings changes require an administrator.');
 }
}

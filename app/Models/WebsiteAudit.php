<?php
namespace App\Models;
class WebsiteAudit extends \Illuminate\Database\Eloquent\Model {protected $guarded=[];protected function casts():array{return ['evidence'=>'array','findings'=>'array'];}public function lead(){return $this->belongsTo(Lead::class);}}

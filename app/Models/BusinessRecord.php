<?php
namespace App\Models;
class BusinessRecord extends \Illuminate\Database\Eloquent\Model {
 protected $guarded=[];protected function casts():array{return ['contacts'=>'array','discovery_evidence'=>'array'];}
 public function leads(){return $this->hasMany(Lead::class);}
}

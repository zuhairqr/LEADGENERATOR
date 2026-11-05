<?php
namespace App\Models;
class OperatorAccount extends \Illuminate\Database\Eloquent\Model {protected $guarded=[];protected $hidden=['password_hash'];protected function casts():array{return ['active'=>'boolean'];}}

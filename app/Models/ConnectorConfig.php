<?php
namespace App\Models;
class ConnectorConfig extends \Illuminate\Database\Eloquent\Model {public $incrementing=false;protected $keyType='string';protected $guarded=[];protected function casts():array{return ['settings'=>'array','enabled'=>'boolean'];}}

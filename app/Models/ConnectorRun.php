<?php
namespace App\Models;
class ConnectorRun extends \Illuminate\Database\Eloquent\Model {protected $guarded=[];protected function casts():array{return ['retry_after'=>'datetime'];}}

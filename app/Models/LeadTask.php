<?php
namespace App\Models;
class LeadTask extends \Illuminate\Database\Eloquent\Model {protected $guarded=[];protected function casts():array{return ['due_at'=>'datetime','completed_at'=>'datetime'];}}

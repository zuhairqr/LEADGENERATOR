<?php
namespace App\Models;
class ChannelMessage extends \Illuminate\Database\Eloquent\Model {public $incrementing=false;protected $keyType='string';protected $guarded=[];protected function casts():array{return ['approved_at'=>'datetime','sent_at'=>'datetime'];}public function lead(){return $this->belongsTo(Lead::class);}public function digest(ConnectorConfig $connector):string{return hash('sha256',json_encode([$this->id,$this->recipient,$this->body,$this->lead_id,$connector->id,$connector->endpoint,$connector->settings,$connector->enabled,$connector->updated_at?->format('c')]));}}

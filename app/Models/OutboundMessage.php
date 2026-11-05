<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OutboundMessage extends Model {
    public $incrementing=false; protected $keyType='string'; protected $guarded=[];
    protected function casts(): array { return ['approved_at'=>'datetime','sending_at'=>'datetime','sent_at'=>'datetime','followup_due_at'=>'datetime']; }
    public function lead() { return $this->belongsTo(Lead::class); }
    public function scopeSales(\Illuminate\Database\Eloquent\Builder $query):\Illuminate\Database\Eloquent\Builder{return $query->whereHas('lead',fn($q)=>$q->prospects());}
    public function digest(array $sender = []): string {
        return hash('sha256',json_encode([$this->id,$this->revision,$this->lead_id,$this->sequence,$this->recipient,$this->subject,$this->body,
            $sender['from_email'] ?? $this->from_email,$sender['from_name'] ?? $this->from_name,$sender['provider'] ?? $this->transport],JSON_THROW_ON_ERROR));
    }
}

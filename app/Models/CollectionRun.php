<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class CollectionRun extends Model {
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected function casts(): array { return ['finished_at' => 'datetime','quality_summary'=>'array']; }
}

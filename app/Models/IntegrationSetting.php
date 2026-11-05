<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class IntegrationSetting extends Model {
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['key', 'data'];
    protected function casts(): array { return ['data' => 'array']; }
}

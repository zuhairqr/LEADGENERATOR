<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class IntegrationSecret extends Model {
    protected $primaryKey='key'; public $incrementing=false; protected $keyType='string';
    protected $fillable=['key','data']; protected $hidden=['data'];
    protected function casts(): array { return ['data'=>'encrypted:array']; }
}

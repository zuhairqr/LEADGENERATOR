<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    public const STATUSES = ['new', 'shortlisted', 'approved', 'contacted', 'won', 'lost', 'rejected'];
    protected $fillable = ['source', 'source_id', 'url_hash', 'url', 'title', 'description', 'budget_min', 'budget_max', 'currency', 'published_at', 'listing_expires_at', 'country_code', 'match_score', 'matched_services', 'match_reason'];
    protected function casts(): array
    {
        return ['matched_services' => 'array', 'published_at' => 'datetime', 'listing_expires_at' => 'datetime', 'drafted_at' => 'datetime', 'budget_min' => 'decimal:2', 'budget_max' => 'decimal:2',
            'contact_permission_at'=>'datetime','replied_at'=>'datetime','bounced_at'=>'datetime','unsubscribed_at'=>'datetime','ai_qualified'=>'boolean','next_action_at'=>'datetime','country_reviewed_at'=>'datetime'];
    }
    public function business(){return $this->belongsTo(BusinessRecord::class,'business_record_id');}
    public function scopeProspects(\Illuminate\Database\Eloquent\Builder $query):\Illuminate\Database\Eloquent\Builder{return $query->where('kind','!=',\App\Services\MailConnectionTest::KIND);}
    public function scopeOpenListings(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where(fn ($q) => $q->whereNull('listing_expires_at')->orWhere('listing_expires_at', '>', now()));
    }
    public function appliesOnSource(): bool
    {
        $source = config('leadgen.sources.'.$this->source, []);
        return !empty($source['apply_on_source'])
            || in_array(strtolower((string) parse_url($this->url, PHP_URL_HOST)), $source['issuer_hosts'] ?? [], true);
    }
    public function audits(){return $this->hasMany(WebsiteAudit::class);}
    public function notes(){return $this->hasMany(LeadNote::class);}
    public function tasks(){return $this->hasMany(LeadTask::class);}
    public function refreshNextAction(): void
    {
        $this->next_action_at = $this->tasks()->whereNull('completed_at')->min('due_at');
        $this->save();
    }
    public function provenances(){return $this->hasMany(SourceProvenance::class);}
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Business extends Model
{
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id', 'name', 'legal_name', 'description', 'primary_category', 'secondary_categories',
        'country', 'default_language', 'website_url', 'status', 'onboarded_at',
        'public_slug', 'is_published', 'published_at', 'published_by',
    ];

    protected function casts(): array
    {
        return ['secondary_categories' => 'array', 'onboarded_at' => 'datetime', 'is_published' => 'boolean', 'published_at' => 'datetime'];
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(BusinessAlias::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(BusinessLocation::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(BusinessContact::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(BusinessService::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(BusinessProduct::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(DigitalAsset::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(DomainProperty::class);
    }

    public function crawlRuns(): HasMany
    {
        return $this->hasMany(CrawlRun::class);
    }

    public function latestCrawlRun(): HasOne
    {
        return $this->hasOne(CrawlRun::class)->latestOfMany();
    }

    public function issues(): HasMany
    {
        return $this->hasMany(AuditIssue::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ActionTask::class);
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class);
    }

    public function keywordClusters(): HasMany
    {
        return $this->hasMany(KeywordCluster::class);
    }

    public function contentAssets(): HasMany
    {
        return $this->hasMany(ContentAsset::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(IntegrationConnection::class);
    }

    public function hasWebsite(): bool
    {
        return ! empty($this->website_url);
    }
}

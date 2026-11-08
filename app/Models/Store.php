<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $name
 * @property string $slug
 * @property string $platform
 * @property string|null $platform_store_id
 * @property string|null $domain
 * @property string|null $logo_path
 * @property string|null $category
 * @property string $status
 * @property string|null $suspended_reason
 * @property string $connection_status
 * @property string|null $zid_authorization
 * @property string|null $zid_access_token
 * @property string|null $zid_refresh_token
 * @property Carbon|null $zid_token_expires_at
 * @property string $sync_frequency
 * @property Carbon|null $last_synced_at
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $brand_guide
 * @property string|null $ai_monthly_budget
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'owner_id', 'name', 'slug', 'platform', 'platform_store_id', 'domain', 'logo_path', 'category',
    'status', 'suspended_reason', 'connection_status', 'zid_authorization', 'zid_access_token',
    'zid_refresh_token', 'zid_token_expires_at', 'sync_frequency', 'last_synced_at', 'settings',
    'brand_guide', 'ai_monthly_budget',
])]
#[Hidden(['zid_authorization', 'zid_access_token', 'zid_refresh_token'])]
class Store extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'platform' => 'zid',
        'status' => 'active',
        'connection_status' => 'disconnected',
        'sync_frequency' => 'daily',
    ];

    protected function casts(): array
    {
        return [
            'zid_authorization' => 'encrypted',
            'zid_access_token' => 'encrypted',
            'zid_refresh_token' => 'encrypted',
            'zid_token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'settings' => 'array',
            'brand_guide' => 'array',
            'ai_monthly_budget' => 'decimal:2',
        ];
    }

    /**
     * Read a store setting, falling back to the platform default.
     */
    public function setting(string $key): mixed
    {
        return Arr::get($this->settings ?? [], $key, PlatformSetting::storeDefault($key));
    }

    /** Latest posts read per social competitor scan: the store's choice, else the server default. */
    public function socialPostsLimit(): int
    {
        $limit = (int) ($this->settings['social_posts_limit'] ?? config('mojo.apify.posts_limit'));

        return max(1, min((int) config('mojo.apify.max_posts_limit'), $limit));
    }

    /**
     * Criteria weights (FR-3.3), normalised so they always sum to 100.
     *
     * @return array<string, float>
     */
    public function weights(): array
    {
        $weights = array_merge(PlatformSetting::storeDefault('weights'), $this->settings['weights'] ?? []);
        $total = array_sum($weights) ?: 1;

        return array_map(fn ($w) => round($w * 100 / $total, 2), $weights);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isConnected(): bool
    {
        return $this->connection_status === 'connected';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return HasMany<ProductSale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(ProductSale::class);
    }

    /**
     * @return HasMany<Competitor, $this>
     */
    public function competitors(): HasMany
    {
        return $this->hasMany(Competitor::class);
    }

    /**
     * @return HasMany<CompetitorProduct, $this>
     */
    public function competitorProducts(): HasMany
    {
        return $this->hasMany(CompetitorProduct::class);
    }

    /**
     * @return HasMany<SocialPost, $this>
     */
    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    /**
     * @return HasMany<CustomerReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(CustomerReview::class);
    }

    /**
     * @return HasMany<Trend, $this>
     */
    public function trends(): HasMany
    {
        return $this->hasMany(Trend::class);
    }

    /**
     * @return HasMany<Evaluation, $this>
     */
    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }

    /**
     * @return HasMany<Design, $this>
     */
    public function designs(): HasMany
    {
        return $this->hasMany(Design::class);
    }

    /**
     * @return HasMany<AiUsage, $this>
     */
    public function aiUsages(): HasMany
    {
        return $this->hasMany(AiUsage::class);
    }

    /**
     * @return HasMany<ActivityLog, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * @return HasMany<SyncRun, $this>
     */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    /**
     * @return HasMany<SeasonalEvent, $this>
     */
    public function seasonalEvents(): HasMany
    {
        return $this->hasMany(SeasonalEvent::class);
    }

    /**
     * AI spend for the current calendar month, in USD.
     */
    public function aiSpendThisMonth(): float
    {
        return (float) $this->aiUsages()->where('created_at', '>=', now()->startOfMonth())->sum('cost');
    }

    public function aiBudgetExceeded(): bool
    {
        return $this->ai_monthly_budget !== null && $this->aiSpendThisMonth() >= (float) $this->ai_monthly_budget;
    }
}

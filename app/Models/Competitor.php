<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $store_id
 * @property string $name
 * @property string $platform
 * @property string|null $url
 * @property string|null $handle
 * @property string|null $category
 * @property bool $is_active
 * @property Carbon|null $last_checked_at
 * @property string|null $last_error
 * @property string|null $notes
 * @property array<string, mixed>|null $analysis
 */
#[Fillable(['store_id', 'name', 'platform', 'url', 'handle', 'category', 'is_active', 'last_checked_at', 'last_error', 'notes', 'analysis'])]
class Competitor extends Model
{
    public const STORE_PLATFORMS = ['website', 'salla', 'zid', 'shopify'];

    public const SOCIAL_PLATFORMS = ['instagram', 'tiktok', 'facebook', 'snapchat'];

    /** Public profile address for a social account entered by handle. */
    private const PROFILE_URLS = [
        'instagram' => 'https://www.instagram.com/%s/',
        'tiktok' => 'https://www.tiktok.com/@%s',
        'facebook' => 'https://www.facebook.com/%s',
        'snapchat' => 'https://www.snapchat.com/add/%s',
    ];

    protected function casts(): array
    {
        return [
            'analysis' => 'array',
            'is_active' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    public function isSocial(): bool
    {
        return in_array($this->platform, self::SOCIAL_PLATFORMS, true);
    }

    /**
     * The social platform a link belongs to, if any: an Instagram, TikTok,
     * Facebook or Snapchat link saved as a website must be read as that
     * account (those sites forbid crawlers; their posts come through Apify).
     */
    public static function socialPlatformOf(?string $url): ?string
    {
        $host = strtolower((string) parse_url(trim((string) $url), PHP_URL_HOST));

        return match (true) {
            $host === '' => null,
            (bool) preg_match('/(^|\.)instagram\.com$/', $host) => 'instagram',
            (bool) preg_match('/(^|\.)tiktok\.com$/', $host) => 'tiktok',
            (bool) preg_match('/(^|\.)(facebook\.com|fb\.com)$/', $host) => 'facebook',
            (bool) preg_match('/(^|\.)snapchat\.com$/', $host) => 'snapchat',
            default => null,
        };
    }

    /**
     * Turn a social link saved under a store platform into that social
     * account. Returns true when the competitor changed.
     */
    public function correctSocialPlatform(): bool
    {
        $social = $this->isSocial() ? null : self::socialPlatformOf($this->url);
        if (! $social) {
            return false;
        }

        $this->update(['platform' => $social, 'handle' => $this->handle ?: self::normalizeHandle($this->url)]);

        return true;
    }

    /** "@name", "name" or a pasted profile link, reduced to the account name. */
    public static function normalizeHandle(?string $value): ?string
    {
        $value = trim((string) $value);
        // Facebook pages without a username: profile.php?id=<number>.
        if (preg_match('~facebook\.com/profile\.php\?(?:[^#\s]*&)?id=(\d+)~i', $value, $match)) {
            return $match[1];
        }
        if (preg_match('~^(?:https?://)?(?:[\w-]+\.)*(?:instagram|tiktok|facebook|snapchat)\.com/(?:add/)?@?([^/?#\s]+)~i', $value, $match)) {
            $value = rawurldecode($match[1]);
        }
        $value = ltrim($value, '@');

        return $value === '' ? null : $value;
    }

    /** The saved link, or the account's public profile built from its handle. */
    public function profileUrl(): ?string
    {
        if ($this->url || ! $this->isSocial() || blank($this->handle)) {
            return $this->url;
        }

        return sprintf(self::PROFILE_URLS[$this->platform], rawurlencode($this->handle));
    }

    /** @return HasOne<CompetitorScan, $this> */
    public function latestScan(): HasOne
    {
        return $this->hasOne(CompetitorScan::class)->latestOfMany();
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return HasMany<CompetitorProduct, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(CompetitorProduct::class);
    }

    /**
     * @return HasMany<SocialPost, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }
}

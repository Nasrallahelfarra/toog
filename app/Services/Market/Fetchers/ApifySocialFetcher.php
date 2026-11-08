<?php

namespace App\Services\Market\Fetchers;

use App\Models\Competitor;
use App\Models\SocialPost;
use App\Services\Market\ApifyClient;
use App\Services\Market\ApifyException;
use App\Services\Market\FeatureExtractor;
use App\Services\Market\PublicWebClient;
use App\Services\Market\SocialAnalysis;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads a competitor's public Instagram, TikTok or Facebook posts through
 * Apify, saves them with their engagement, then analyses the content.
 */
class ApifySocialFetcher implements CompetitorFetcher
{
    public const PLATFORMS = ['instagram', 'tiktok', 'facebook'];

    /** Time allowed for copying post images during one scan. */
    private const IMAGE_SECONDS = 60;

    public function __construct(
        private readonly ApifyClient $apify,
        private readonly FeatureExtractor $features,
        private readonly SocialAnalysis $analysis,
        private readonly PublicWebClient $web,
    ) {}

    public function supports(Competitor $competitor): bool
    {
        return in_array($competitor->platform, self::PLATFORMS, true)
            && filled($competitor->handle ?: $competitor->url) && $this->apify->configured();
    }

    public function fetch(Competitor $competitor, ?Closure $progress = null): FetchResult
    {
        try {
            return $this->read($competitor, $progress);
        } catch (ApifyException $e) {
            return FetchResult::failed($e->getMessage(), $e->reason);
        }
    }

    private function read(Competitor $competitor, ?Closure $progress): FetchResult
    {
        $progress?->__invoke('rules', []);
        $handle = Competitor::normalizeHandle($competitor->handle ?: $competitor->url) ?? '';
        $profile = $this->profileUrl($competitor, $handle);
        $limit = max(1, min(100, (int) config('mojo.apify.posts_limit')));
        $since = now()->subDays(max(1, (int) config('mojo.apify.days')));
        $observedAt = now()->toIso8601String();
        $sources = $warnings = [];

        $progress?->__invoke('storefront', []);
        $followers = $competitor->platform === 'instagram' ? $this->instagramFollowers($handle, $profile, $sources, $warnings) : null;

        $progress?->__invoke('products', []);
        $actor = (string) config('mojo.apify.actors.'.$competitor->platform);
        $items = $this->apify->run($actor, $this->input($competitor->platform, $handle, $profile, $limit, $since), $limit);
        $sources[] = ['url' => $profile, 'actor' => $actor, 'items' => count($items)];
        $posts = collect($items)->map(fn (array $item) => $this->normalize($competitor->platform, $item, $followers))
            ->filter()->unique('external_id')->take($limit)->values();
        $progress?->__invoke('products', ['posts' => $posts->count()]);

        if ($posts->isEmpty()) {
            $error = collect($items)->first(fn (array $item) => isset($item['error']));

            return FetchResult::failed($error
                ? 'Apify: '.Str::limit((string) ($error['errorDescription'] ?? $error['error']), 200)
                : "No public posts were found for @{$handle}. Check the handle and that the account is public.", 'no_posts');
        }

        $progress?->__invoke('saving', ['posts' => $posts->count()]);
        $posts = $this->keepImages($competitor, $posts);
        $result = new FetchResult(FetchResult::OK);
        $ids = $this->save($competitor, $posts, $observedAt, $result);

        $progress?->__invoke('analysis', ['posts' => count($ids)]);
        $this->analysis->build($competitor, $ids, [
            'observed_at' => $observedAt, 'profile_url' => $profile, 'since' => $since->toDateString(),
            'sources' => $sources, 'warnings' => $warnings, 'limited' => $posts->count() >= $limit,
        ]);

        return $result;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $posts
     * @return list<int>
     */
    private function save(Competitor $competitor, Collection $posts, string $observedAt, FetchResult $result): array
    {
        return DB::transaction(function () use ($competitor, $posts, $observedAt, $result): array {
            $ids = [];
            foreach ($posts as $post) {
                $evidence = [...$post['evidence'], 'observed_at' => $observedAt];
                $data = [
                    ...collect($post)->except('evidence')->all(),
                    'store_id' => $competitor->store_id, 'platform' => $competitor->platform, 'evidence' => $evidence,
                    'features' => $this->features->fromText(($post['caption'] ?? '').' '.implode(' ', $evidence['hashtags'])) ?: null,
                ];
                $existing = SocialPost::query()->where('competitor_id', $competitor->id)->where('external_id', $post['external_id'])->first();
                if ($existing) {
                    $existing->update($data);
                    $result->updated++;
                } else {
                    $existing = SocialPost::query()->create([...$data, 'competitor_id' => $competitor->id]);
                    $result->added++;
                }
                $ids[] = $existing->id;
            }

            return $ids;
        });
    }

    /**
     * Instagram and Facebook image links expire and refuse to load on other
     * sites, so each post image is copied once to the public disk. Copying
     * stops after IMAGE_SECONDS; posts without a copy keep the remote link.
     *
     * @param  Collection<int, array<string, mixed>>  $posts
     * @return Collection<int, array<string, mixed>>
     */
    private function keepImages(Competitor $competitor, Collection $posts): Collection
    {
        $disk = Storage::disk('public');
        $deadline = microtime(true) + self::IMAGE_SECONDS;

        return $posts->map(function (array $post) use ($competitor, $disk, $deadline): array {
            $base = "social-posts/{$competitor->id}/".sha1($post['external_id']);
            $path = collect(['jpg', 'png', 'webp'])->map(fn ($ext) => "{$base}.{$ext}")->first(fn ($p) => $disk->exists($p));
            if (! $path && $post['thumbnail_url'] && microtime(true) < $deadline) {
                try {
                    $response = $this->web->get($post['thumbnail_url']);
                    $info = $response->successful() ? @getimagesizefromstring($response->body()) : false;
                    $ext = $info ? (['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime']] ?? null) : null;
                    if ($ext && $disk->put("{$base}.{$ext}", $response->body())) {
                        $path = "{$base}.{$ext}";
                    }
                } catch (Throwable) {
                    // The remote link stays; the post is still saved.
                }
            }
            $post['evidence']['image_path'] = $path;

            return $post;
        });
    }

    /** @return array<string, mixed> */
    private function input(string $platform, string $handle, string $profile, int $limit, CarbonInterface $since): array
    {
        return match ($platform) {
            'instagram' => ['username' => [$handle], 'resultsLimit' => $limit, 'onlyPostsNewerThan' => $since->toDateString(), 'skipPinnedPosts' => false],
            'tiktok' => ['profiles' => [$handle], 'resultsPerPage' => $limit, 'profileSorting' => 'latest', 'excludePinnedPosts' => false,
                'shouldDownloadVideos' => false, 'shouldDownloadCovers' => false, 'shouldDownloadSlideshowImages' => false,
                'shouldDownloadAvatars' => false, 'shouldDownloadMusicCovers' => false],
            'facebook' => ['startUrls' => [['url' => $profile]], 'resultsLimit' => $limit, 'onlyPostsNewerThan' => $since->toDateString()],
        };
    }

    /**
     * One Apify item as a post row, or null for error and empty items.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function normalize(string $platform, array $item, ?int $followers): ?array
    {
        $count = fn (mixed $value) => is_numeric($value) ? max(0, (int) $value) : 0;
        $text = fn (mixed $value) => is_string($value) && trim($value) !== '' ? Str::limit(trim($value), 5000, '') : null;
        $link = fn (mixed $value) => is_string($value) && preg_match('~^https://~i', $value) ? $value : null;
        $tags = fn (mixed $values) => collect(is_array($values) ? $values : [])
            ->map(fn ($tag) => is_array($tag) ? ($tag['name'] ?? null) : $tag)
            ->filter(fn ($tag) => is_string($tag) && $tag !== '')->map(fn ($tag) => ltrim($tag, '#'))->unique()->take(30)->values()->all();

        $post = match ($platform) {
            'instagram' => [
                'external_id' => $item['id'] ?? $item['shortCode'] ?? null,
                'url' => $link($item['url'] ?? null) ?? (isset($item['shortCode']) ? 'https://www.instagram.com/p/'.rawurlencode((string) $item['shortCode']).'/' : null),
                'caption' => $text($item['caption'] ?? null),
                'thumbnail_url' => $link($item['displayUrl'] ?? null),
                'views' => $count($item['videoPlayCount'] ?? $item['videoViewCount'] ?? 0),
                'likes' => $count($item['likesCount'] ?? 0),
                'comments' => $count($item['commentsCount'] ?? 0),
                'shares' => 0,
                'posted_at' => $item['timestamp'] ?? null,
                'hashtags' => $tags($item['hashtags'] ?? []),
                'type' => match (strtolower((string) ($item['type'] ?? ''))) {
                    'video' => 'video', 'sidecar' => 'carousel', default => 'image',
                },
                'likes_hidden' => ($item['likesCount'] ?? null) === -1,
                'followers' => $followers,
            ],
            'tiktok' => [
                'external_id' => $item['id'] ?? null,
                'url' => $link($item['webVideoUrl'] ?? null),
                'caption' => $text($item['text'] ?? null),
                'thumbnail_url' => $link($item['videoMeta']['coverUrl'] ?? null),
                'views' => $count($item['playCount'] ?? 0),
                'likes' => $count($item['diggCount'] ?? 0),
                'comments' => $count($item['commentCount'] ?? 0),
                'shares' => $count($item['shareCount'] ?? 0),
                'posted_at' => $item['createTimeISO'] ?? (is_numeric($item['createTime'] ?? null) ? '@'.$item['createTime'] : null),
                'hashtags' => $tags($item['hashtags'] ?? []),
                'type' => ! empty($item['isSlideshow']) ? 'carousel' : 'video',
                'likes_hidden' => false,
                'followers' => $count($item['authorMeta']['fans'] ?? 0) ?: null,
            ],
            'facebook' => [
                'external_id' => $item['postId'] ?? $item['id'] ?? null,
                'url' => $link($item['url'] ?? null),
                'caption' => $text($item['text'] ?? null),
                'thumbnail_url' => $link($item['media'][0]['thumbnail'] ?? $item['media'][0]['photo_image']['uri'] ?? null),
                'views' => $count($item['viewsCount'] ?? 0),
                'likes' => $count($item['likes'] ?? 0),
                'comments' => $count($item['comments'] ?? 0),
                'shares' => $count($item['shares'] ?? 0),
                'posted_at' => $item['time'] ?? (is_numeric($item['timestamp'] ?? null) ? '@'.$item['timestamp'] : null),
                'hashtags' => preg_match_all('~#([\p{L}\p{N}_]+)~u', (string) ($item['text'] ?? ''), $m) ? array_slice(array_unique($m[1]), 0, 30) : [],
                'type' => ! empty($item['viewsCount']) ? 'video' : (! empty($item['media']) ? 'image' : 'text'),
                'likes_hidden' => false,
                'followers' => null,
            ],
        };

        if (isset($item['error']) || ! is_scalar($post['external_id']) || (string) $post['external_id'] === '') {
            return null;
        }
        try {
            $postedAt = $post['posted_at'] ? Carbon::parse($post['posted_at']) : null;
        } catch (Throwable) {
            $postedAt = null;
        }

        // Interactions against followers when known, otherwise against views.
        $interactions = $post['likes'] + $post['comments'] + $post['shares'];
        [$rate, $basis] = match (true) {
            $post['followers'] > 0 => [$interactions / $post['followers'] * 100, 'followers'],
            $post['views'] > 0 => [$interactions / $post['views'] * 100, 'views'],
            default => [0, null],
        };

        return [
            'external_id' => Str::limit((string) $post['external_id'], 190, ''),
            'url' => $post['url'], 'caption' => $post['caption'], 'thumbnail_url' => $post['thumbnail_url'],
            'views' => $post['views'], 'likes' => $post['likes'], 'comments' => $post['comments'], 'shares' => $post['shares'],
            'engagement_rate' => min(9999.99, round($rate, 2)),
            'posted_at' => $postedAt?->isFuture() ? null : $postedAt,
            'evidence' => [
                'source' => 'apify', 'type' => $post['type'], 'hashtags' => $post['hashtags'],
                'followers' => $post['followers'], 'engagement_basis' => $basis, 'likes_hidden' => $post['likes_hidden'],
            ],
        ];
    }

    /**
     * Instagram posts carry no follower count; read it from the profile so
     * engagement compares like with like. A failure only costs that ratio.
     *
     * @param  list<array<string, mixed>>  $sources
     * @param  list<array<string, string>>  $warnings
     */
    private function instagramFollowers(string $handle, string $profile, array &$sources, array &$warnings): ?int
    {
        $actor = (string) config('mojo.apify.actors.instagram_profile');
        if ($actor === '') {
            return null;
        }
        try {
            $items = $this->apify->run($actor, ['usernames' => [$handle]], 1, 120);
            $sources[] = ['url' => $profile, 'actor' => $actor, 'items' => count($items)];
            $followers = $items[0]['followersCount'] ?? null;

            return is_numeric($followers) && $followers > 0 ? (int) $followers : null;
        } catch (ApifyException $e) {
            if (in_array($e->reason, ['apify_unauthorized', 'apify_payment'], true)) {
                throw $e;
            }
            $warnings[] = ['url' => $profile, 'code' => 'followers_unavailable',
                'message' => 'The follower count could not be read; engagement uses video views where available.'];

            return null;
        }
    }

    private function profileUrl(Competitor $competitor, string $handle): string
    {
        $host = strtolower((string) parse_url((string) $competitor->url, PHP_URL_HOST));
        $own = ['instagram' => 'instagram.com', 'tiktok' => 'tiktok.com', 'facebook' => 'facebook.com'][$competitor->platform];

        return $competitor->url && ($host === $own || str_ends_with($host, '.'.$own))
            ? $competitor->url
            : (string) (new Competitor(['platform' => $competitor->platform, 'handle' => $handle]))->profileUrl();
    }
}

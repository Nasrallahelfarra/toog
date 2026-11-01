<?php

namespace App\Services\Zid;

use App\Models\Store;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Zid merchant API (FR-1.1).
 *
 * Auth: the OAuth token response holds two tokens — `authorization` (sent as
 * "Authorization: Bearer …", identifies the app) and `access_token` (sent as
 * "X-Manager-Token", identifies the store).
 *
 * Checked against docs.zid.sa: the OAuth flow, token fields and headers,
 * and the orders list (managers/store/orders with page / per_page /
 * payload_type / date_from). The products list path and response shape
 * are not yet confirmed on a live store; paths are constants so they can
 * be corrected in one place, and the parsers accept the common shapes
 * (results / data / products / orders).
 */
class ZidClient
{
    public const AUTHORIZE_PATH = '/oauth/authorize';

    public const TOKEN_PATH = '/oauth/token';

    public const PROFILE_PATH = 'managers/account/profile';

    public const STORE_PATH = 'managers/account/store';

    public const PRODUCTS_PATH = 'products/';

    public const ORDERS_PATH = 'managers/store/orders';

    public const PER_PAGE = 50;

    public const MAX_PAGES = 200;

    /**
     * @param  int  $timeout  seconds per request; the connection check uses a short one
     */
    public function __construct(private readonly Store $store, private readonly int $timeout = 45) {}

    public static function configured(): bool
    {
        return filled(config('mojo.zid.client_id')) && filled(config('mojo.zid.client_secret'));
    }

    public static function authorizeUrl(string $state): string
    {
        return rtrim(config('mojo.zid.oauth_url'), '/').self::AUTHORIZE_PATH.'?'.http_build_query([
            'client_id' => config('mojo.zid.client_id'),
            'redirect_uri' => config('mojo.zid.redirect_uri'),
            'response_type' => 'code',
            'state' => $state,
        ]);
    }

    /**
     * Exchange an authorization code (or a refresh token) for tokens.
     *
     * @param  array<string, mixed>  $grant
     * @return array{authorization: string|null, access_token: string|null, refresh_token: string|null, expires_in: int|null}
     */
    public static function token(array $grant): array
    {
        $response = Http::asForm()->acceptJson()->timeout(30)->post(
            rtrim(config('mojo.zid.oauth_url'), '/').self::TOKEN_PATH,
            [
                ...$grant,
                'client_id' => config('mojo.zid.client_id'),
                'client_secret' => config('mojo.zid.client_secret'),
                'redirect_uri' => config('mojo.zid.redirect_uri'),
            ],
        );

        if ($response->failed()) {
            throw new RuntimeException('Zid token request failed: '.($response->json('message') ?? $response->json('error_description') ?? $response->status()));
        }

        return [
            'authorization' => $response->json('authorization'),
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_in' => $response->json('expires_in') !== null ? (int) $response->json('expires_in') : null,
        ];
    }

    /**
     * Save tokens on the store and mark it connected.
     *
     * @param  array{authorization: string|null, access_token: string|null, refresh_token: string|null, expires_in: int|null}  $tokens
     */
    public static function storeTokens(Store $store, array $tokens): void
    {
        $store->forceFill([
            'zid_authorization' => $tokens['authorization'] ?? $store->zid_authorization,
            'zid_access_token' => $tokens['access_token'] ?? $store->zid_access_token,
            'zid_refresh_token' => $tokens['refresh_token'] ?? $store->zid_refresh_token,
            'zid_token_expires_at' => $tokens['expires_in'] ? now()->addSeconds($tokens['expires_in']) : null,
            'connection_status' => 'connected',
            'platform' => 'zid',
        ])->save();
    }

    /**
     * Refresh the tokens when they are about to expire.
     */
    public function refreshIfNeeded(): void
    {
        $expires = $this->store->zid_token_expires_at;

        if (! $expires || $expires->isAfter(now()->addMinutes(10)) || ! $this->store->zid_refresh_token) {
            return;
        }

        self::storeTokens($this->store, self::token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->store->zid_refresh_token,
        ]));
        $this->store->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        return $this->request()->get(self::PROFILE_PATH)->throw()->json() ?? [];
    }

    /**
     * The store identity must come from Zid, not a previous Store-Id header.
     *
     * @return array<string, mixed>
     */
    public function storeProfile(): array
    {
        $response = $this->request(includeStoreId: false)->get(self::STORE_PATH);
        if ($response->failed()) {
            throw new RuntimeException('Could not verify the Zid store (HTTP '.$response->status().'). Check the third_store_details_read permission and reactivate the app.', $response->status());
        }
        $profile = $response->json();
        $id = data_get($profile, 'store.id');
        if ((! is_int($id) && ! is_string($id)) || ! ctype_digit((string) $id) || (int) $id < 1) {
            throw new RuntimeException('Zid did not return a valid store identity. Reconnect the app before importing data.');
        }

        return $profile;
    }

    /**
     * All products, page by page.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function products(?callable $onPage = null): Generator
    {
        yield from $this->paginate(self::PRODUCTS_PATH, ['page_size' => self::PER_PAGE], ['results', 'products', 'data'], $onPage);
    }

    /**
     * Orders created since a date, page by page.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function orders(\DateTimeInterface $since, ?callable $onPage = null): Generator
    {
        // payload_type=default is the only list format that includes each
        // order's products (the default "simple" omits them), which the
        // best-seller analysis needs. Dates use Zid's documented format.
        yield from $this->paginate(self::ORDERS_PATH, [
            'per_page' => self::PER_PAGE,
            'payload_type' => 'default',
            'date_attribute' => 'created_at',
            'date_from' => $since->format('Y-m-d\TH:i:s.vO'),
            'order_by' => 'created_at',
            'sort_by' => 'asc',
        ], ['orders', 'results', 'data'], $onPage);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  list<string>  $keys  where the list may sit in the payload
     * @return Generator<int, array<string, mixed>>
     */
    private function paginate(string $path, array $query, array $keys, ?callable $onPage = null): Generator
    {
        $read = 0;
        $seenPages = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            try {
                $json = $this->request()->get($path, [...$query, 'page' => $page])->throw()->json() ?? [];
            } catch (RequestException $e) {
                throw new RuntimeException("Zid request to {$path} failed (HTTP ".$e->response->status().').', previous: $e);
            }

            $items = null;
            foreach ($keys as $key) {
                if (isset($json[$key]) && is_array($json[$key]) && array_is_list($json[$key])) {
                    $items = $json[$key];
                    break;
                }
            }

            if ($items === null) {
                throw new RuntimeException("Zid returned an invalid list for {$path}.");
            }
            $fingerprint = hash('sha256', json_encode(array_map(fn ($item) => $item['id'] ?? $item, $items), JSON_THROW_ON_ERROR));
            if ($items !== [] && isset($seenPages[$fingerprint])) {
                throw new RuntimeException("Zid repeated a page for {$path}; import stopped to avoid duplicate totals.");
            }
            $seenPages[$fingerprint] = true;

            foreach ($items as $item) {
                if (is_array($item)) {
                    yield $item;
                    $read++;
                }
            }
            $onPage && $onPage($page, $read);

            $last = $json['last_page'] ?? $json['pagination']['last_page'] ?? null;
            $hasNext = $last !== null ? $page < (int) $last
                : (array_key_exists('next', $json) ? filled($json['next']) : count($items) >= self::PER_PAGE);
            if (! $hasNext) {
                return;
            }
            if ($items === []) {
                throw new RuntimeException("Zid returned an empty page with more pages for {$path}.");
            }
        }
        throw new RuntimeException('Zid import exceeded the page limit; no partial catalog was saved.');
    }

    private function request(bool $includeStoreId = true): PendingRequest
    {
        if (! $this->store->zid_access_token) {
            throw new RuntimeException('The store has no Zid credentials. Connect it from Settings → Integration.');
        }

        return Http::baseUrl(rtrim(config('mojo.zid.api_url'), '/').'/')
            ->withHeaders(array_filter([
                'Authorization' => $this->store->zid_authorization ? 'Bearer '.$this->store->zid_authorization : null,
                'X-Manager-Token' => $this->store->zid_access_token,
                'Accept-Language' => 'ar',
                'Store-Id' => $includeStoreId ? $this->store->platform_store_id : null,
            ]))
            ->acceptJson()
            ->timeout($this->timeout)
            ->retry($this->timeout < 45 ? 0 : 2, 1000, throw: false);
    }
}

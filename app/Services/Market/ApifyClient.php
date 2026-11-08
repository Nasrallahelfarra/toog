<?php

namespace App\Services\Market;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Runs an Apify actor synchronously and returns its dataset items. Used to
 * read competitors' public social posts; the token stays on the server.
 */
class ApifyClient
{
    /** Apify stops synchronous runs at 300 seconds. */
    private const MAX_RUN_SECONDS = 290;

    public function configured(): bool
    {
        return filled(config('mojo.apify.token'));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     *
     * @throws ApifyException
     */
    public function run(string $actor, array $input, int $maxItems, ?int $timeout = null): array
    {
        $timeout = max(30, min(self::MAX_RUN_SECONDS, $timeout ?? (int) config('mojo.apify.timeout')));
        $url = rtrim((string) config('mojo.apify.base_url'), '/').'/acts/'.rawurlencode($actor).'/run-sync-get-dataset-items';

        try {
            $response = Http::withToken((string) config('mojo.apify.token'))
                ->acceptJson()->connectTimeout(10)->timeout($timeout + 30)
                // maxItems caps what a pay-per-result actor may bill for this run.
                ->withQueryParameters(['timeout' => $timeout, 'maxItems' => $maxItems, 'clean' => 'true'])
                ->post($url, $input);
        } catch (ConnectionException) {
            throw new ApifyException('Apify could not be reached. Try again shortly.', 'apify_unavailable');
        }

        if (! $response->successful()) {
            $detail = Str::limit((string) ($response->json('error.message') ?? ''), 160);
            [$reason, $message] = match (true) {
                in_array($response->status(), [401, 403], true) => ['apify_unauthorized', 'Apify rejected the API token.'],
                $response->status() === 402 => ['apify_payment', 'The Apify account has no credit left for this actor.'],
                $response->status() === 404 => ['apify_unavailable', "The Apify actor {$actor} was not found."],
                $response->status() === 408 => ['apify_timeout', 'Apify did not finish reading the posts in time.'],
                default => ['apify_unavailable', 'Apify returned HTTP '.$response->status().'.'],
            };

            throw new ApifyException(trim($message.' '.$detail), $reason);
        }

        $items = $response->json();
        if (! is_array($items) || ! array_is_list($items)) {
            throw new ApifyException('Apify returned an unexpected response.', 'apify_unavailable');
        }

        return array_values(array_filter($items, 'is_array'));
    }
}

<?php

namespace App\Services\Market;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

/** Bounded requests to public storefronts, including redirect and DNS checks. */
class PublicWebClient
{
    public function absolute(string $base, string $link): string
    {
        return (string) UriResolver::resolve(new Uri($base), new Uri(html_entity_decode($link)));
    }

    /** @return list<string> */
    public function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        return array_values(array_unique(array_merge(
            gethostbynamel($host) ?: [],
            array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        )));
    }

    /**
     * @param  array<string, string>  $headers  extra request headers (e.g. a public storefront API key)
     */
    public function get(string $url, int $redirects = 0, array $headers = []): Response
    {
        $parts = parse_url($url);
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        $scheme = $parts['scheme'] ?? '';
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($scheme, ['http', 'https'], true) || ! $host || isset($parts['user']) || isset($parts['pass'])
            || ! in_array($port, [80, 443], true) || $host === 'localhost' || str_ends_with($host, '.local')) {
            throw new RuntimeException('Only public HTTP/HTTPS storefront URLs are supported.');
        }

        $addresses = $this->addresses($host);
        if (! $addresses) {
            throw new RuntimeException('The storefront hostname could not be resolved.');
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || str_starts_with(strtolower($address), '::ffff:')
                || IpUtils::checkIp($address, ['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4', '64:ff9b::/96'])) {
                throw new RuntimeException('Private and reserved network addresses cannot be crawled.');
            }
        }

        $ip = str_contains($addresses[0], ':') ? "[{$addresses[0]}]" : $addresses[0];
        $response = Http::connectTimeout(5)->timeout(15)
            ->withHeaders(['User-Agent' => 'Toog-MarketMonitor/1.0 (+public catalog)', 'Accept' => 'text/html,application/json', ...$headers])
            ->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
                'proxy' => '',
                'progress' => function ($total, $downloaded): void {
                    if ($total > 5_000_000 || $downloaded > 5_000_000) {
                        throw new RuntimeException('The public page exceeds the crawl size limit.');
                    }
                },
            ])->get($url);

        if ($response->redirect()) {
            if ($redirects >= 3 || ! $response->header('Location')) {
                throw new RuntimeException('Too many storefront redirects.');
            }

            return $this->get($this->absolute($url, $response->header('Location')), $redirects + 1, $headers);
        }
        if (strlen($response->body()) > 5_000_000) {
            throw new RuntimeException('The public page exceeds the crawl size limit.');
        }

        return $response;
    }

    /** Apply the most specific matching robots rule for this crawler. */
    public function allowed(string $robots, string $url): bool
    {
        $groups = [];
        $agents = [];
        $rules = [];
        foreach (explode("\n", $robots."\nUser-agent: __end__") as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if (! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            if (strtolower($key) === 'user-agent') {
                if ($rules) {
                    $groups[] = [$agents, $rules];
                    $agents = $rules = [];
                }
                $agents[] = strtolower($value);
            } elseif (in_array(strtolower($key), ['allow', 'disallow'], true) && $value !== '') {
                $rules[] = [strtolower($key), $value];
            }
        }
        $specific = array_filter($groups, fn ($g) => count(array_filter($g[0], fn ($a) => $a !== '*' && str_contains('toog-marketmonitor', $a))) > 0);
        $groups = $specific ?: array_filter($groups, fn ($g) => in_array('*', $g[0], true));
        $path = (parse_url($url, PHP_URL_PATH) ?: '/').(($query = parse_url($url, PHP_URL_QUERY)) ? '?'.$query : '');
        $allowed = true;
        $length = -1;
        foreach ($groups as [, $rules]) {
            foreach ($rules as [$type, $rule]) {
                $pattern = str_replace(['\\*', '\\$'], ['.*', '$'], preg_quote($rule, '~'));
                $size = strlen(str_replace(['*', '$'], '', $rule));
                if (preg_match('~^'.$pattern.'~', $path) && ($size > $length || ($size === $length && $type === 'allow'))) {
                    $length = $size;
                    $allowed = $type === 'allow';
                }
            }
        }

        return $allowed;
    }
}

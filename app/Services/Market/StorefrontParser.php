<?php

namespace App\Services\Market;

use DOMDocument;
use DOMElement;
use DOMXPath;

/** Extract explicit public evidence; ordering alone never proves sales. */
class StorefrontParser
{
    public function __construct(private readonly PublicWebClient $web) {}

    /** @return array{best_collections: array<string, string>, product_links: array<string, string>, products: list<array<string, mixed>>, currency: string|null, shopify: bool} */
    public function parse(string $html, string $url): array
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($doc);
        $collections = $links = $products = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }
            $label = trim(preg_replace('/\s+/u', ' ', $anchor->textContent));
            $link = $this->publicLink($url, $anchor->getAttribute('href'));
            if ($link && mb_strlen($label) < 100 && preg_match('/best[\s-]*sell(?:er|ing)s?|الأكثر\s*مبيع|الاكثر\s*مبيع/iu', $label)) {
                if (str_contains(parse_url($link, PHP_URL_PATH) ?: '', '/collections/')) {
                    $collections[$link] = $label;
                }
            }
        }
        foreach ($xpath->query('//main//a[@href] | //*[@role="main"]//a[@href]') ?: [] as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }
            $link = $this->publicLink($url, $anchor->getAttribute('href'));
            if ($link && preg_match('~/products/([^/?#]+)~', $link, $match)) {
                $links[rawurldecode($match[1])] = $link;
            }
        }
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            if (! $script instanceof DOMElement) {
                continue;
            }
            $json = json_decode($script->textContent, true);
            if (is_array($json)) {
                $this->structuredProducts($json, $url, $products);
            }
        }
        $currency = null;
        if (preg_match('/Shopify\.currency\s*=\s*(\{[^;]+\})/', $html, $match)) {
            $currency = json_decode($match[1], true)['active'] ?? null;
        }

        return ['best_collections' => array_slice($collections, 0, 2, true), 'product_links' => $links,
            'products' => $products, 'currency' => $currency, 'shopify' => str_contains($html, 'Shopify.')];
    }

    public function publicLink(string $base, string $link): ?string
    {
        try {
            $absolute = $this->web->absolute($base, $link);
        } catch (\Throwable) {
            return null;
        }
        $parts = parse_url($absolute);
        if (! in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            || ($parts['host'] ?? null) !== parse_url($base, PHP_URL_HOST)
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return strtok($absolute, '#') ?: null;
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<array<string, mixed>>  $products
     */
    private function structuredProducts(array $data, string $source, array &$products): void
    {
        if (in_array('Product', (array) ($data['@type'] ?? []), true) && ! empty($data['name'])) {
            $offers = $data['offers'] ?? [];
            if (array_is_list($offers)) {
                $offers = $offers[0] ?? [];
            }
            $url = $this->publicLink($source, (string) ($data['url'] ?? $offers['url'] ?? $source));
            if ($url) {
                $image = $data['image'] ?? null;
                $image = is_array($image) ? ($image[0] ?? $image['url'] ?? null) : $image;
                $sales = null;
                foreach ($data['additionalProperty'] ?? [] as $property) {
                    if (is_array($property) && preg_match('/^(salesCount|unitsSold|عدد المبيعات|عدد القطع المباعة)$/iu', $property['name'] ?? '')) {
                        $sales = ['text' => $property['name'].': '.($property['value'] ?? ''), 'url' => $source];
                    }
                }
                $products[] = ['id' => hash('sha256', $url), 'title' => $data['name'], 'url' => $url,
                    'product_type' => is_string($data['category'] ?? null) ? $data['category'] : null,
                    'body_html' => $data['description'] ?? '', 'image_url' => $image,
                    'variants' => [['price' => $offers['price'] ?? $offers['lowPrice'] ?? null]],
                    'currency' => $offers['priceCurrency'] ?? null, 'published_sales' => $sales];
            }
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $this->structuredProducts($value, $source, $products);
            }
        }
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A page loads its scripts and fonts from the domain it was opened on, so
 * the laravel.cloud address and a custom domain both work.
 */
class AssetDomainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false, 'app.url' => 'https://touk.ai', 'app.asset_url' => 'https://toog-production.laravel.cloud']);
    }

    public function test_each_of_our_domains_serves_its_own_assets(): void
    {
        $this->get('https://touk.ai/')->assertOk()->assertSee('https://touk.ai/build/', false)
            ->assertDontSee('https://toog-production.laravel.cloud/build/', false);

        $this->get('https://toog-production.laravel.cloud/')->assertOk()->assertSee('https://toog-production.laravel.cloud/build/', false);
    }

    public function test_an_unknown_host_keeps_the_configured_asset_domain(): void
    {
        $this->get('https://evil.example/')->assertOk()->assertDontSee('https://evil.example/build/', false);
    }
}

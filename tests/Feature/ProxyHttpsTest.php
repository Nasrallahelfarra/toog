<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Jobs\ScanCompetitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProxyHttpsTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'toog-production-nitj3p.laravel.cloud';

    private mixed $previousCloudEnv;

    private mixed $previousCloudServer;

    protected function setUp(): void
    {
        $this->previousCloudEnv = $_ENV['LARAVEL_CLOUD'] ?? null;
        $this->previousCloudServer = $_SERVER['LARAVEL_CLOUD'] ?? null;
        // Hosting flags are present before the HTTP kernel is bootstrapped.
        $_ENV['LARAVEL_CLOUD'] = $_SERVER['LARAVEL_CLOUD'] = '1';
        parent::setUp();
        config(['app.asset_url' => null]);
        Route::get('/proxy-url-check', fn () => response()->json([
            'secure' => request()->isSecure(),
            'stylesheet' => asset('build/assets/app.css'),
            'script' => asset('build/assets/app.js'),
            'font' => asset('build/assets/arabic.woff2'),
        ]));
    }

    protected function tearDown(): void
    {
        if ($this->previousCloudEnv === null) {
            unset($_ENV['LARAVEL_CLOUD']);
        } else {
            $_ENV['LARAVEL_CLOUD'] = $this->previousCloudEnv;
        }
        if ($this->previousCloudServer === null) {
            unset($_SERVER['LARAVEL_CLOUD']);
        } else {
            $_SERVER['LARAVEL_CLOUD'] = $this->previousCloudServer;
        }
        parent::tearDown();
    }

    private function proxy(string $address): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => $address, 'SERVER_PORT' => 80, 'HTTPS' => 'off',
            'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_PORT' => '443',
        ]);
    }

    public function test_cloud_ingress_generates_secure_stylesheet_script_and_font_urls(): void
    {
        $this->proxy('10.20.30.40');
        $this->get('http://'.self::HOST.'/proxy-url-check')->assertOk()->assertExactJson([
            'secure' => true,
            'stylesheet' => 'https://'.self::HOST.'/build/assets/app.css',
            'script' => 'https://'.self::HOST.'/build/assets/app.js',
            'font' => 'https://'.self::HOST.'/build/assets/arabic.woff2',
        ]);
    }

    public function test_competitor_scan_redirect_stays_https_behind_cloud_ingress(): void
    {
        $this->proxy('10.20.30.40');
        config(['queue.default' => 'database']);
        Queue::fake();
        $owner = User::factory()->create();
        $store = app(CreateStore::class)->handle($owner, ['name' => 'Test store']);
        $competitor = $store->competitors()->create(['name' => 'Rival', 'platform' => 'website', 'url' => 'https://shop.example']);
        $this->actingAs($owner)->post('http://'.self::HOST.'/store/competitors/'.$competitor->id.'/scan')
            ->assertRedirect('https://'.self::HOST.'/store/competitors?competitor='.$competitor->id);
        Queue::assertPushed(ScanCompetitor::class, 1);
    }
}

<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** A new deploy must reach open tabs even when ASSET_URL is fixed. */
class InertiaAssetVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_build_changes_the_version_and_reloads_stale_tabs(): void
    {
        $public = storage_path('framework/testing/public-'.uniqid());
        File::ensureDirectoryExists("{$public}/build");
        $this->app->usePublicPath($public);
        config(['app.asset_url' => 'https://toog.example']);
        $version = fn () => app(HandleInertiaRequests::class)->version(Request::create('/'));

        try {
            File::put("{$public}/build/manifest.json", '{"resources/js/app.tsx":{"file":"assets/app-OLD.js"}}');
            $old = $version();
            File::put("{$public}/build/manifest.json", '{"resources/js/app.tsx":{"file":"assets/app-NEW.js"}}');
            $this->assertNotSame($old, $version());

            // A tab still on the old build is told to reload instead of fetching removed chunks.
            $this->actingAs(User::factory()->create())
                ->get('/settings/profile', ['X-Inertia' => 'true', 'X-Inertia-Version' => $old])
                ->assertStatus(409);
        } finally {
            File::deleteDirectory($public);
        }
    }
}

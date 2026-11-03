<?php

namespace Tests\Feature;

use App\Services\Ai\Ai;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RecommendationTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_timeout_is_bounded_without_shortening_other_ai_calls(): void
    {
        config(['mojo.ai.provider' => 'gemini', 'mojo.ai.gemini.api_key' => 'test-key', 'mojo.ai.gemini.timeout' => 500]);
        Http::preventStrayRequests();
        $timeouts = [];
        Http::fake(function ($request, $options) use (&$timeouts) {
            $timeouts[] = $options['timeout'];
            $this->assertSame(10, $options['connect_timeout']);

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]]]);
        });
        $ai = app(Ai::class);
        $recovery = $ai->json('product_recovery', null, 'system', 'prompt', [], fn () => [], timeoutSeconds: 120);
        $other = $ai->json('other_operation', null, 'system', 'prompt', [], fn () => []);
        $this->assertTrue($recovery->fromModel);
        $this->assertTrue($other->fromModel);
        $this->assertSame([120, 500], $timeouts);
        Http::assertSentCount(2);
    }

    public function test_a_lower_configured_timeout_is_respected(): void
    {
        config(['mojo.ai.provider' => 'gemini', 'mojo.ai.gemini.api_key' => 'test-key', 'mojo.ai.gemini.timeout' => 30]);
        Http::preventStrayRequests();
        Http::fake(function ($request, $options) {
            $this->assertSame(30, $options['timeout']);

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]]]);
        });
        $this->assertTrue(app(Ai::class)->json('product_recovery', null, 'system', 'prompt', [], fn () => [], timeoutSeconds: 120)->fromModel);
        Http::assertSentCount(1);
    }
}

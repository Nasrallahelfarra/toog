<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Models\Evaluation;
use App\Models\Product;
use App\Models\Store;
use App\Models\Trend;
use App\Models\User;
use App\Services\Evaluation\Candidate;
use App\Services\Evaluation\EvaluationEngine;
use App\Services\Evaluation\EvaluationService;
use App\Services\Evaluation\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvaluationEngineTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mojo.ai.gemini.api_key' => null]); // demo mode: no network

        $owner = User::factory()->create();
        $this->store = app(CreateStore::class)->handle($owner, ['name' => 'Test store']);

        // Best sellers: black/sand crepe embroidery around 300-380 SAR.
        foreach ([[300, ['black']], [340, ['black', 'sand']], [380, ['sand']]] as $i => [$price, $colors]) {
            $this->product("Winner {$i}", Product::WINNER, $price, $colors, 'embroidery', 'crepe', 'luxury');
        }

        // Slow movers: red velvet florals at the expensive end.
        foreach ([680, 720, 750] as $i => $price) {
            $this->product("Slow {$i}", Product::STAGNANT, $price, ['red', 'mustard'], 'floral', 'velvet', 'bohemian');
        }

        Trend::query()->create([
            'store_id' => $this->store->id, 'type' => 'color', 'value' => 'sand', 'name_ar' => 'رملي', 'name_en' => 'Sand',
            'momentum' => 80, 'growth' => 20, 'saturation' => 30, 'status' => 'rising',
        ]);
    }

    private function product(string $name, string $class, int $price, array $colors, string $pattern, string $material, string $style): void
    {
        Product::query()->create([
            'store_id' => $this->store->id, 'name' => $name, 'category' => 'abayas', 'price' => $price,
            'performance' => $class, 'features' => compact('colors', 'pattern', 'material', 'style'),
        ]);
    }

    private function score(Candidate $c): array
    {
        return app(EvaluationEngine::class)->score($c, new StoreContext($this->store));
    }

    public function test_the_same_candidate_always_gets_the_same_score()
    {
        $candidate = new Candidate('abayas', 340, ['black', 'sand'], 'embroidery', 'crepe', 'luxury', 'all_year');

        $this->assertSame($this->score($candidate)['score'], $this->score($candidate)['score']);
    }

    public function test_a_best_seller_lookalike_scores_higher_than_a_slow_mover_lookalike()
    {
        $good = $this->score(new Candidate('abayas', 340, ['black', 'sand'], 'embroidery', 'crepe', 'luxury', 'all_year'));
        $bad = $this->score(new Candidate('abayas', 720, ['red', 'mustard'], 'floral', 'velvet', 'bohemian', 'summer'));

        $this->assertGreaterThan($bad['score'] + 20, $good['score']);
        $this->assertSame(Evaluation::REJECT, $bad['recommendation']);
        $this->assertNotEmpty($bad['evidence']['similar_stagnant']);
    }

    public function test_score_is_a_weighted_sum_of_six_criteria_between_0_and_100()
    {
        $result = $this->score(new Candidate('abayas', 500, ['navy'], 'solid', 'linen', 'modern', 'all_year'));

        $this->assertCount(6, $result['criteria']);
        $this->assertEqualsWithDelta(100, array_sum(array_column($result['criteria'], 'weight')), 0.1);
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }

    public function test_suggested_modifications_actually_raise_the_score()
    {
        $candidate = new Candidate('abayas', 650, ['navy'], 'stripes', 'linen', 'sporty', 'all_year');
        $ctx = new StoreContext($this->store);
        $engine = app(EvaluationEngine::class);
        $result = $engine->score($candidate, $ctx);

        $mods = $engine->modifications($candidate, $ctx, $result);

        $this->assertNotEmpty($mods['modifications']);
        $this->assertGreaterThan($result['score'], $mods['expected_score']);
    }

    public function test_thresholds_from_store_settings_drive_the_decision()
    {
        $candidate = new Candidate('abayas', 340, ['black', 'sand'], 'embroidery', 'crepe', 'luxury', 'all_year');
        $score = $this->score($candidate)['score'];

        $this->store->update(['settings' => ['accept_threshold' => min(100, $score + 1), 'reject_threshold' => 10]]);
        $this->assertSame(Evaluation::MODIFY, $this->score($candidate)['recommendation']);

        $this->store->update(['settings' => ['accept_threshold' => max(0, $score - 1), 'reject_threshold' => 10]]);
        $this->assertSame(Evaluation::ACCEPT, $this->score($candidate)['recommendation']);
    }

    public function test_the_service_completes_an_evaluation_in_demo_mode_and_records_ai_usage()
    {
        $evaluation = Evaluation::query()->create([
            'store_id' => $this->store->id, 'name' => 'Candidate', 'category' => 'abayas', 'price' => 330,
            'colors' => ['black', 'sand'], 'pattern' => 'embroidery', 'material' => 'crepe', 'style' => 'luxury',
            'season' => 'all_year',
        ]);

        app(EvaluationService::class)->run($evaluation, notify: false);
        $evaluation->refresh();

        $this->assertSame('completed', $evaluation->status);
        $this->assertNotNull($evaluation->score);
        $this->assertNotEmpty($evaluation->explanation_ar);
        $this->assertNotEmpty($evaluation->explanation_en);
        $this->assertDatabaseHas('ai_usages', ['store_id' => $this->store->id, 'operation' => 'evaluation', 'provider' => 'fake']);
    }
}

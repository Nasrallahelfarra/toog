<?php

namespace Database\Seeders;

use App\Actions\Stores\CreateStore;
use App\Models\ActivityLog;
use App\Models\AiUsage;
use App\Models\Competitor;
use App\Models\CompetitorProduct;
use App\Models\CustomerReview;
use App\Models\Design;
use App\Models\DesignRequest;
use App\Models\Evaluation;
use App\Models\EvaluationBatch;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\SocialPost;
use App\Models\Store;
use App\Models\Trend;
use App\Models\User;
use App\Notifications\PlatformNotification;
use App\Services\Ai\Ai;
use App\Services\Analytics\PerformanceClassifier;
use App\Services\Evaluation\EvaluationService;
use App\Services\Evaluation\StoreContext;
use App\Support\Vocabulary;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

/**
 * Demo stores with a year of sales, competitors, trends, reviews,
 * evaluations, designs and AI usage — enough to exercise every screen.
 */
class DemoStoresSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(2026);

        // Generated images from a previous seed would be orphaned by the reset.
        Storage::disk('public')->deleteDirectory('designs');
        Storage::disk('public')->deleteDirectory('evaluations');

        $lama = $this->store('lama@demo.sa', 'لمى العتيبي', 'دار لمى للعبايات', 'darlama.zid.store', 'أزياء محتشمة');
        $lama->update(['connection_status' => 'connected', 'last_synced_at' => now()->subHours(5), 'ai_monthly_budget' => 50,
            'brand_guide' => [
                'colors' => ['#1C1C1E', '#C8A97E', '#EFE8D8', '#C9A227'],
                'fonts' => ['IBM Plex Sans Arabic', 'Amiri'],
                'style_ar' => 'فخامة هادئة، قصّات انسيابية، تطريز ناعم، تصوير على خلفيات رملية دافئة',
                'style_en' => 'Quiet luxury, flowing cuts, soft embroidery, shot on warm sand backdrops',
                'logo_path' => null,
            ]]);
        $this->catalog($lama, $this->fashionProfile());
        $this->market($lama, $this->fashionMarket());
        $this->evaluations($lama, $this->fashionCandidates());
        $this->designs($lama);

        $hadaya = $this->store('hadaya@demo.sa', 'فهد القحطاني', 'بيت الهدايا', 'baitalhadaya.com', 'هدايا وديكور');
        $hadaya->update(['connection_status' => 'connected', 'last_synced_at' => now()->subDay(), 'sync_frequency' => 'weekly',
            'brand_guide' => [
                'colors' => ['#1F7A55', '#C9A227', '#F7F7F5', '#7B5134'],
                'fonts' => ['IBM Plex Sans Arabic'],
                'style_ar' => 'تراث سعودي بلمسة عصرية، خط عربي، خامات طبيعية',
                'style_en' => 'Saudi heritage with a modern touch, Arabic calligraphy, natural materials',
            ]]);
        $this->catalog($hadaya, $this->giftsProfile());
        $this->market($hadaya, $this->giftsMarket());
        $this->evaluations($hadaya, $this->giftsCandidates());

        $najd = $this->store('najd@demo.sa', 'سعود الدوسري', 'نجد للعود والعطور', 'najdoud.zid.store', 'عطور');
        $najd->update(['status' => Store::STATUS_SUSPENDED, 'suspended_reason' => 'انتهاء الاشتراك وعدم التجديد', 'connection_status' => 'error']);
        $this->catalog($najd, $this->perfumeProfile());

        $rose = $this->store('rose@demo.sa', 'روان الشهري', 'متجر روز', null, 'إكسسوارات');
        $rose->owner->update(['status' => User::STATUS_DISABLED, 'disabled_at' => now()->subDays(3), 'disabled_reason' => 'طلب المالك إيقاف الحساب مؤقتًا']);

        $this->aiHistory([$lama, $hadaya, $najd]);
        $this->activity($lama);
        $this->activity($hadaya);
        $this->notifications($lama);
    }

    private function store(string $email, string $owner, string $name, ?string $domain, string $category): Store
    {
        $user = User::query()->create([
            'name' => $owner,
            'email' => $email,
            'password' => 'password',
            'role' => User::ROLE_STORE_OWNER,
            'locale' => 'ar',
            'email_verified_at' => now(),
            'last_login_at' => now()->subHours(mt_rand(1, 72)),
            'phone' => '05'.mt_rand(10000000, 99999999),
        ]);

        $store = app(CreateStore::class)->handle($user, ['name' => $name, 'domain' => $domain, 'category' => $category]);
        $store->update(['platform_store_id' => (string) mt_rand(100000, 999999), 'created_at' => now()->subMonths(mt_rand(4, 14))]);

        return $store->fresh();
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function catalog(Store $store, array $profile): void
    {
        $months = collect(range(11, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i));
        $n = 0;

        foreach ($profile['categories'] as $category => $spec) {
            foreach (['win' => $spec['count'][0], 'mid' => $spec['count'][1], 'lose' => $spec['count'][2]] as $dna => $count) {
                for ($i = 0; $i < $count; $i++) {
                    $n++;
                    $features = $this->features($profile['dna'][$dna], $spec);
                    $price = mt_rand(...($profile['dna'][$dna]['price'][$category] ?? $profile['dna'][$dna]['price']['*']));
                    $launched = $n % 9 === 0 ? now()->subDays(mt_rand(10, 70)) : now()->subDays(mt_rand(120, 540));

                    $product = Product::query()->create([
                        'store_id' => $store->id,
                        'external_id' => 'Z'.(10000 + $n + $store->id * 1000),
                        'sku' => strtoupper(substr($store->slug, 0, 3)).'-'.(1000 + $n),
                        'name' => $this->productName($spec['noun'], $features),
                        'category' => $category,
                        'price' => $price,
                        'features' => $features,
                        'stock' => match ($dna) { 'win' => mt_rand(20, 70), 'mid' => mt_rand(40, 120), default => mt_rand(140, 320) },
                        'is_novel' => $n % 9 === 0 ? $n % 27 === 0 : ($dna !== 'win' && mt_rand(0, 4) === 0),
                        'launched_at' => $launched,
                    ]);

                    $base = match ($dna) { 'win' => mt_rand(45, 95), 'mid' => mt_rand(14, 36), default => mt_rand(1, 8) };

                    foreach ($months as $month) {
                        if ($month->lessThan($launched->copy()->startOfMonth())) {
                            continue;
                        }

                        $seasonal = $profile['seasonality'][$month->month] ?? 1;
                        $units = max(0, (int) round($base * $seasonal * (0.75 + mt_rand(0, 50) / 100)));
                        $views = $units * mt_rand(22, 40) + mt_rand(80, 400);
                        $cartRate = match ($dna) { 'win' => mt_rand(8, 13), 'mid' => mt_rand(5, 8), default => mt_rand(2, 4) } / 100;
                        $returnRate = match ($dna) { 'win' => mt_rand(2, 7), 'mid' => mt_rand(6, 12), default => mt_rand(12, 26) } / 100;

                        ProductSale::query()->create([
                            'store_id' => $store->id,
                            'product_id' => $product->id,
                            'period' => $month->toDateString(),
                            'orders' => (int) round($units * 0.92),
                            'units' => $units,
                            'revenue' => $units * $price,
                            'views' => $views,
                            'add_to_cart' => (int) round($views * $cartRate),
                            'returns' => (int) round($units * $returnRate),
                        ]);
                    }
                }
            }
        }

        // A few data-quality problems for FR-1.7 to report.
        Product::query()->where('store_id', $store->id)->inRandomOrder()->limit(2)->update(['category' => null]);
        Product::query()->where('store_id', $store->id)->inRandomOrder()->limit(1)->update(['price' => 0]);
        $dup = Product::query()->where('store_id', $store->id)->first();
        Product::query()->create([...Arr::except($dup->getAttributes(), ['id', 'created_at', 'updated_at']), 'external_id' => $dup->external_id.'-dup', 'features' => $dup->features]);

        app(PerformanceClassifier::class)->classify($store);
    }

    /**
     * @param  array<string, mixed>  $dna
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function features(array $dna, array $spec): array
    {
        $colors = Arr::random($dna['colors'], mt_rand(1, 2));

        return [
            'colors' => array_values(array_unique((array) $colors)),
            'pattern' => Arr::random($dna['patterns']),
            'material' => Arr::random($spec['materials'][$dna['key']] ?? $spec['materials']['*']),
            'style' => Arr::random($dna['styles']),
            'season' => Arr::random($dna['seasons']),
            'pattern_complexity' => Arr::random(['low', 'medium', 'high']),
            'typography' => Arr::random(['none', 'arabic_calligraphy', 'modern_arabic']),
        ];
    }

    /**
     * @param  array<string, mixed>  $features
     */
    private function productName(string $noun, array $features): string
    {
        $color = Vocabulary::label('colors', $features['colors'][0], 'ar');
        $pattern = Vocabulary::label('patterns', $features['pattern'], 'ar');
        $style = Vocabulary::label('styles', $features['style'], 'ar');

        return trim("{$noun} {$style} {$pattern} {$color}");
    }

    /**
     * @param  array<string, mixed>  $market
     */
    private function market(Store $store, array $market): void
    {
        foreach ($market['competitors'] as [$name, $platform, $url, $handle]) {
            $competitor = Competitor::query()->create([
                'store_id' => $store->id, 'name' => $name, 'platform' => $platform, 'url' => $url, 'handle' => $handle,
                'category' => $store->category, 'last_checked_at' => now()->subHours(mt_rand(2, 30)),
            ]);

            if (in_array($platform, Competitor::STORE_PLATFORMS, true)) {
                for ($i = 0; $i < 7; $i++) {
                    $features = $this->features($market['competitor_dna'], $market['competitor_spec']);
                    $category = Arr::random(array_keys($market['competitor_spec']['nouns']));
                    $price = mt_rand(...$market['competitor_price']);
                    CompetitorProduct::query()->create([
                        'store_id' => $store->id, 'competitor_id' => $competitor->id, 'external_id' => "c{$competitor->id}-{$i}",
                        'name' => $this->productName($market['competitor_spec']['nouns'][$category], $features),
                        'category' => $category, 'price' => $price,
                        'previous_price' => mt_rand(0, 4) === 0 ? $price + mt_rand(20, 80) : null,
                        'url' => rtrim((string) $url, '/')."/p/{$i}", 'is_best_seller' => mt_rand(0, 3) === 0,
                        'features' => $features, 'first_seen_at' => now()->subDays(mt_rand(1, 90)), 'last_seen_at' => now()->subHours(mt_rand(1, 48)),
                    ]);
                }
            } else {
                foreach (array_slice($market['posts'], 0, 5) as $j => $caption) {
                    $views = mt_rand(15_000, 1_800_000);
                    $likes = (int) ($views * mt_rand(3, 11) / 100);
                    SocialPost::query()->create([
                        'store_id' => $store->id, 'competitor_id' => $competitor->id, 'platform' => $platform,
                        'url' => "https://{$platform}.com/{$handle}/{$j}", 'caption' => $caption,
                        'views' => $views, 'likes' => $likes, 'shares' => (int) ($likes * mt_rand(5, 25) / 100),
                        'comments' => (int) ($likes * mt_rand(2, 9) / 100),
                        'engagement_rate' => round($likes / $views * 100 + mt_rand(0, 150) / 100, 2),
                        'features' => $this->features($market['competitor_dna'], $market['competitor_spec']),
                        'posted_at' => now()->subDays(mt_rand(1, 45)),
                    ]);
                }
                $market['posts'] = array_merge(array_slice($market['posts'], 5), array_slice($market['posts'], 0, 5));
            }
        }

        foreach ($market['trends'] as [$type, $value, $ar, $en, $momentum, $growth, $saturation, $status, $platforms]) {
            $inCatalog = Product::query()->where('store_id', $store->id)->get()->contains(fn (Product $p) => match ($type) {
                'color' => in_array($value, $p->features['colors'] ?? [], true),
                'theme' => ($p->features['season'] ?? null) === $value,
                default => ($p->features[$type] ?? null) === $value,
            });

            Trend::query()->create([
                'store_id' => $store->id, 'type' => $type, 'value' => $value, 'name_ar' => $ar, 'name_en' => $en,
                'momentum' => $momentum, 'growth' => $growth, 'saturation' => $saturation, 'status' => $status,
                'platforms' => $platforms, 'in_catalog' => $inCatalog, 'detected_at' => now()->subDays(mt_rand(2, 40)),
            ]);
        }

        foreach ($market['reviews'] as [$text, $rating, $sentiment, $topics, $lang]) {
            CustomerReview::query()->create([
                'store_id' => $store->id, 'source' => mt_rand(0, 2) ? 'store' : 'competitor',
                'product_name' => Product::query()->where('store_id', $store->id)->inRandomOrder()->value('name'),
                'text' => $text, 'rating' => $rating, 'language' => $lang,
                'sentiment' => $sentiment, 'sentiment_score' => $sentiment ? ['positive' => 0.8, 'neutral' => 0.0, 'negative' => -0.7][$sentiment] : null,
                'topics' => $topics ?: null, 'analyzed_at' => $sentiment ? now()->subDays(mt_rand(1, 20)) : null,
                'created_at' => now()->subDays(mt_rand(1, 60)),
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    private function evaluations(Store $store, array $candidates): void
    {
        $service = app(EvaluationService::class);
        $ctx = new StoreContext($store);
        $owner = $store->owner;

        $batch = EvaluationBatch::query()->create(['store_id' => $store->id, 'user_id' => $owner->id, 'name' => $store->id === 1 ? 'تشكيلة رمضان ١٤٤٨' : 'تشكيلة الشتاء']);

        foreach ($candidates as $i => $data) {
            $created = now()->subDays(55 - $i * 3)->setTime(mt_rand(9, 20), mt_rand(0, 59));
            $evaluation = Evaluation::query()->create([
                ...Arr::except($data, ['decision', 'versions']),
                'store_id' => $store->id, 'user_id' => $owner->id, 'source' => $i >= count($candidates) - 4 ? 'batch' : 'manual',
                'batch_id' => $i >= count($candidates) - 4 ? $batch->id : null, 'status' => 'pending',
            ]);
            $evaluation->update(['root_id' => $evaluation->id]);
            $service->run($evaluation, $ctx, notify: false);

            // An improved second version that applies the suggested changes (FR-3.7).
            if (($data['versions'] ?? false) && $evaluation->modifications) {
                $changes = collect($evaluation->modifications)->reduce(fn ($carry, $m) => [...$carry, ...$m['change']], []);
                $v2 = Evaluation::query()->create([
                    ...Arr::only($evaluation->getAttributes(), ['store_id', 'user_id', 'name', 'category', 'material', 'pattern', 'style', 'season', 'occasion', 'description']),
                    'price' => $changes['price'] ?? $evaluation->price,
                    'colors' => $changes['colors'] ?? $evaluation->colors,
                    'pattern' => $changes['pattern'] ?? $evaluation->pattern,
                    'material' => $changes['material'] ?? $evaluation->material,
                    'style' => $changes['style'] ?? $evaluation->style,
                    'season' => $changes['season'] ?? $evaluation->season,
                    'parent_id' => $evaluation->id, 'root_id' => $evaluation->id, 'version' => 2,
                    'source' => 'manual', 'status' => 'pending',
                ]);
                $service->run($v2, $ctx, notify: false);
                $v2->update(['created_at' => $created->addDays(2)]);
            }

            $evaluation->update(['created_at' => $created]);

            if ($decision = $data['decision'] ?? null) {
                $evaluation->update([
                    'final_decision' => $decision[0], 'override_reason' => $decision[1] ?? null,
                    'decided_by' => $owner->id, 'decided_at' => $created->addHours(mt_rand(2, 30)),
                ]);
            }
        }
    }

    private function designs(Store $store): void
    {
        $ai = app(Ai::class);
        $source = Evaluation::query()->where('store_id', $store->id)->where('recommendation', 'modify')->first();

        $requests = [
            ['modification', 'نسخة محسّنة: '.($source?->name ?? 'عباية'), 'Abaya, crepe, sand and black palette #C8A97E #1C1C1E #EFE8D8, soft embroidery on sleeves', 'mockup_model', '4:5', $source?->id],
            ['new_idea', 'فكرة جديدة: عباية بنقشة السدو', 'Modern abaya with subtle Sadu weave border, sage and ivory #A3B49A #EFE8D8 #C9A227', 'product_photo', '1:1', null],
            ['new_idea', 'حملة رمضان — صور سوشال', 'Ramadan lifestyle shot, ivory abaya with gold details, lantern light #EFE8D8 #C9A227 #1F7A55', 'social', '9:16', null],
        ];

        $statuses = ['approved', 'in_review', 'draft', 'rejected', 'draft', 'approved', 'in_review', 'draft', 'draft'];
        $k = 0;

        foreach ($requests as [$type, $title, $prompt, $output, $ratio, $evaluationId]) {
            $request = DesignRequest::query()->create([
                'store_id' => $store->id, 'user_id' => $store->owner_id, 'evaluation_id' => $evaluationId, 'type' => $type,
                'title' => $title, 'prompt' => $prompt, 'category' => 'عبايات', 'output_type' => $output,
                'aspect_ratio' => $ratio, 'variants_count' => 3, 'status' => 'completed',
            ]);

            for ($v = 1; $v <= 3; $v++) {
                $image = $ai->image('design', $store, "{$prompt} variant {$v}", aspectRatio: $ratio, userId: $store->owner_id);
                preg_match_all('/#[0-9A-Fa-f]{6}/', $prompt, $m);

                Design::query()->create([
                    'store_id' => $store->id, 'design_request_id' => $request->id, 'user_id' => $store->owner_id,
                    'title' => "{$title} — بديل {$v}", 'image_path' => $image->path, 'output_type' => $output, 'aspect_ratio' => $ratio,
                    'palette' => $m[0], 'tags' => [$type === 'modification' ? 'تعديل' : 'فكرة جديدة', 'عبايات'],
                    'status' => $statuses[$k++ % count($statuses)], 'is_favorite' => $v === 1,
                    'score' => mt_rand(58, 91), 'provider' => $image->provider, 'model' => $image->model, 'cost' => $image->cost, 'prompt' => $prompt,
                ]);
            }
        }
    }

    /**
     * Two months of plausible Gemini usage so the cost dashboard has history.
     *
     * @param  list<Store>  $stores
     */
    private function aiHistory(array $stores): void
    {
        $ai = app(Ai::class);
        $ops = [
            ['evaluation', 'gemini-3.8-flash', [2500, 4200], [700, 1400], 0],
            ['attributes', 'gemini-3.8-flash', [1200, 1800], [200, 400], 0],
            ['sentiment', 'gemini-3.5-flash-lite', [600, 1500], [150, 350], 0],
            ['trend_vision', 'gemini-3.8-flash', [3000, 6000], [400, 900], 0],
            ['design', 'gemini-nano-banana-2.1', [300, 900], [0, 0], 1],
            ['idea', 'gemini-3.8-flash', [1500, 2500], [600, 1200], 0],
        ];

        foreach ($stores as $index => $store) {
            $intensity = [9, 5, 2][$index] ?? 1;

            for ($d = 60; $d >= 1; $d--) {
                $day = now()->subDays($d);
                $calls = mt_rand(0, $intensity) + ($day->isWeekend() ? 0 : 1);

                for ($c = 0; $c < $calls; $c++) {
                    [$op, $model, $in, $out, $images] = Arr::random($ops);
                    $input = mt_rand(...$in);
                    $output = mt_rand(...$out);
                    $failed = mt_rand(0, 40) === 0;

                    AiUsage::query()->create([
                        'store_id' => $store->id, 'user_id' => $store->owner_id, 'provider' => 'gemini', 'model' => $model,
                        'operation' => $op, 'input_tokens' => $input, 'output_tokens' => $failed ? 0 : $output,
                        'images' => $failed ? 0 : $images, 'cost' => $failed ? 0 : $ai->cost($model, $input, $output, $images),
                        'success' => ! $failed, 'error' => $failed ? 'Gemini request failed: 503 overloaded' : null,
                        'latency_ms' => mt_rand(1800, 14000), 'meta' => ['seed' => true],
                        'created_at' => $day->setTime(mt_rand(8, 22), mt_rand(0, 59)),
                    ]);
                }
            }
        }
    }

    private function activity(Store $store): void
    {
        $actions = ['evaluation.created', 'evaluation.decided', 'evaluation.override', 'settings.updated', 'competitor.created', 'design.generated', 'data.synced', 'report.exported'];

        foreach (range(1, 24) as $i) {
            $action = Arr::random($actions);
            ActivityLog::query()->create([
                'user_id' => $store->owner_id, 'store_id' => $store->id, 'action' => $action,
                'properties' => match ($action) {
                    'settings.updated' => ['field' => 'accept_threshold', 'from' => 70, 'to' => 75],
                    'evaluation.override' => ['reason' => 'منتج استراتيجي لموسم رمضان'],
                    'report.exported' => ['report' => 'evaluations', 'format' => 'xlsx'],
                    default => null,
                },
                'ip' => '188.48.'.mt_rand(1, 254).'.'.mt_rand(1, 254), 'created_at' => now()->subHours($i * 7 + mt_rand(0, 6)),
            ]);
        }
    }

    private function notifications(Store $store): void
    {
        $owner = $store->owner;
        $items = [
            ['trend', 'توجه جديد صاعد: نقشة السدو', 'New rising trend: Sadu weave', 'نمو 34% على إنستغرام وتيك توك خلال أسبوعين، وغير موجودة في كتالوجك.', 'Up 34% on Instagram and TikTok in two weeks, and missing from your catalog.', '/store/market', 'info'],
            ['balance', 'تنبيه توازن: فئة العبايات متكررة', 'Balance alert: abayas are repetitive', 'نسبة المنتجات المبتكرة 18% فقط مقابل المستهدف 30%.', 'Only 18% innovative products versus a 30% target.', '/store/balance', 'warning'],
            ['sync', 'اكتملت مزامنة بيانات زد', 'Zid data sync complete', 'تم تحديث 46 منتجًا و 1,240 طلبًا.', '46 products and 1,240 orders updated.', '/store/performance', 'success'],
        ];

        foreach ($items as [$kind, $tAr, $tEn, $bAr, $bEn, $url, $level]) {
            $owner->notify(new PlatformNotification($kind, $tAr, $tEn, $bAr, $bEn, $url, $store->id, $level));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fashionProfile(): array
    {
        return [
            'categories' => [
                'عبايات' => ['noun' => 'عباية', 'count' => [7, 8, 6], 'materials' => ['win' => ['crepe', 'linen'], 'mid' => ['crepe', 'chiffon', 'linen'], 'lose' => ['velvet', 'chiffon'], '*' => ['crepe']]],
                'طرح' => ['noun' => 'طرحة', 'count' => [4, 4, 3], 'materials' => ['win' => ['chiffon', 'silk'], 'mid' => ['chiffon', 'cotton'], 'lose' => ['velvet'], '*' => ['chiffon']]],
                'جلابيات' => ['noun' => 'جلابية', 'count' => [3, 4, 3], 'materials' => ['win' => ['linen', 'cotton'], 'mid' => ['cotton'], 'lose' => ['velvet', 'silk'], '*' => ['cotton']]],
            ],
            'dna' => [
                'win' => ['key' => 'win', 'colors' => ['black', 'black', 'black', 'beige', 'sand'], 'patterns' => ['embroidery', 'embroidery', 'embroidery', 'solid'], 'styles' => ['luxury', 'luxury', 'luxury', 'modern'], 'seasons' => ['all_year', 'ramadan', 'eid_fitr'],
                    'price' => ['عبايات' => [290, 420], 'طرح' => [85, 140], 'جلابيات' => [180, 260], '*' => [100, 300]]],
                'mid' => ['key' => 'mid', 'colors' => ['grey', 'brown', 'olive', 'dusty_pink', 'black', 'teal'], 'patterns' => ['solid', 'geometric', 'stripes', 'embroidery'], 'styles' => ['classic', 'modern', 'heritage'], 'seasons' => ['all_year', 'winter', 'summer'],
                    'price' => ['عبايات' => [230, 480], 'طرح' => [60, 160], 'جلابيات' => [150, 300], '*' => [100, 300]]],
                'lose' => ['key' => 'lose', 'colors' => ['mustard', 'red', 'lavender', 'sky_blue'], 'patterns' => ['floral', 'abstract', 'stripes'], 'styles' => ['bohemian', 'playful', 'sporty'], 'seasons' => ['summer', 'national_day'],
                    'price' => ['عبايات' => [560, 760], 'طرح' => [30, 55], 'جلابيات' => [330, 420], '*' => [100, 300]]],
            ],
            'seasonality' => [1 => 1.1, 2 => 1.9, 3 => 2.2, 4 => 1.0, 5 => 1.3, 6 => 0.8, 7 => 0.7, 8 => 0.9, 9 => 1.2, 10 => 1.0, 11 => 1.4, 12 => 1.1],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fashionMarket(): array
    {
        return [
            'competitors' => [
                ['أناقة سلمى', 'salla', 'https://anaqa-salma.salla.sa', null],
                ['بيت العباية', 'zid', 'https://bait-alabaya.zid.store', null],
                ['Modest Avenue', 'shopify', 'https://modestavenue-sa.myshopify.com', null],
                ['عبايات ترند', 'instagram', 'https://instagram.com/abaya.trends.sa', 'abaya.trends.sa'],
                ['لمسات', 'tiktok', 'https://tiktok.com/@lamsat.sa', 'lamsat.sa'],
                ['ستايل الرياض', 'snapchat', 'https://snapchat.com/add/riyadh.style', 'riyadh.style'],
            ],
            'competitor_dna' => ['key' => 'mid', 'colors' => ['sage', 'terracotta', 'dusty_pink', 'black', 'beige', 'ivory'], 'patterns' => ['sadu', 'geometric', 'embroidery', 'solid'], 'styles' => ['minimal', 'heritage', 'modern'], 'seasons' => ['ramadan', 'all_year', 'winter']],
            'competitor_spec' => ['nouns' => ['عبايات' => 'عباية', 'طرح' => 'طرحة'], 'materials' => ['*' => ['crepe', 'linen', 'chiffon']]],
            'competitor_price' => [180, 520],
            'posts' => [
                'عباية مريمية بخامة الكتان 🌿 الأكثر طلبًا هالموسم', 'تنسيقة رمضان بلون العاجي مع تطريز ذهبي ✨', 'نقشة السدو رجعت بقوة! وش رأيكم؟',
                'قصة الفراشة بالأسود الكلاسيكي 🖤', 'لون التيراكوتا للشتاء — جريئة وأنيقة', 'تنسيق دوام بسيط بالبيج', 'كولكشن العيد: وردي مطفي وذهبي',
                'عباية كتان صيفية خفيفة', 'هندسي ناعم على الأكمام — ترند ٢٠٢٧', 'فتح الصندوق: عباية مطرزة يدويًا',
            ],
            'trends' => [
                ['color', 'sage', 'أخضر مريمية', 'Sage green', 82, 34.5, 35, 'rising', ['instagram', 'tiktok']],
                ['color', 'terracotta', 'تيراكوتا', 'Terracotta', 68, 21.0, 30, 'rising', ['instagram', 'salla']],
                ['color', 'dusty_pink', 'وردي مطفي', 'Dusty pink', 74, 18.2, 48, 'rising', ['tiktok', 'snapchat']],
                ['color', 'beige', 'بيج', 'Beige', 60, 2.1, 78, 'stable', ['salla', 'zid', 'instagram']],
                ['color', 'black', 'أسود', 'Black', 55, -1.5, 86, 'stable', ['salla', 'zid']],
                ['color', 'mustard', 'خردلي', 'Mustard', 28, -22.0, 60, 'declining', ['instagram']],
                ['pattern', 'sadu', 'نقشة السدو', 'Sadu weave', 77, 41.0, 22, 'rising', ['instagram', 'tiktok', 'snapchat']],
                ['pattern', 'geometric', 'نقوش هندسية ناعمة', 'Soft geometric', 64, 15.4, 40, 'rising', ['instagram']],
                ['pattern', 'embroidery', 'تطريز', 'Embroidery', 70, 4.0, 72, 'stable', ['salla', 'zid', 'shopify']],
                ['pattern', 'floral', 'ورود كبيرة', 'Large florals', 35, -18.0, 80, 'declining', ['shopify']],
                ['style', 'minimal', 'البساطة الفاخرة', 'Quiet minimalism', 71, 12.3, 55, 'rising', ['instagram', 'tiktok']],
                ['style', 'heritage', 'لمسات تراثية', 'Heritage touches', 69, 26.7, 33, 'rising', ['tiktok', 'snapchat']],
                ['material', 'linen', 'كتان', 'Linen', 66, 19.8, 45, 'rising', ['instagram', 'shopify']],
                ['theme', 'ramadan', 'تجهيزات رمضان', 'Ramadan collections', 88, 52.0, 50, 'rising', ['instagram', 'tiktok', 'snapchat', 'salla']],
            ],
            'reviews' => [
                ['القماش مره حلو والقصة تجنن 😍', 5, 'positive', ['fabric', 'fit'], 'ar'],
                ['اللون غير عن الصورة مره، طلع أفتح', 2, 'negative', ['color_accuracy'], 'ar'],
                ['التوصيل تأخر بس العباية فخمة وتستاهل', 4, 'neutral', ['delivery', 'quality'], 'ar'],
                ['المقاس صغير شوي، انصحكم تاخذون مقاس أكبر', 3, 'negative', ['sizing'], 'ar'],
                ['التطريز ناعم ومرتب، ثاني مره أطلب منكم', 5, 'positive', ['design', 'quality'], 'ar'],
                ['السعر غالي شوي مقارنة بالخامة', 3, 'negative', ['price', 'fabric'], 'ar'],
                ['Beautiful abaya, the linen is perfect for summer', 5, 'positive', ['fabric'], 'en'],
                ['التغليف راقي جدًا ويصلح هدية', 5, 'positive', ['packaging'], 'ar'],
                ['الخامة تكرمش بسرعة', 2, 'negative', ['fabric'], 'ar'],
                ['ودي تنزلون ألوان زي المريمي والتيراكوتا', 4, null, null, 'ar'],
                ['ما عجبني القصة الواسعة، احسها قديمة', 2, null, null, 'ar'],
                ['خدمة العملاء ممتازة وتجاوبهم سريع', 5, null, null, 'ar'],
                ['Sizes run small, otherwise great quality', 4, null, null, 'en'],
                ['النقشة الهندسية على الأكمام رهيبة', 5, null, null, 'ar'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fashionCandidates(): array
    {
        return [
            ['name' => 'عباية كريب بتطريز رملي', 'category' => 'عبايات', 'price' => 340, 'material' => 'crepe', 'colors' => ['black', 'sand'], 'pattern' => 'embroidery', 'style' => 'luxury', 'season' => 'ramadan', 'decision' => ['approved']],
            ['name' => 'عباية مخمل بورود كبيرة', 'category' => 'عبايات', 'price' => 690, 'material' => 'velvet', 'colors' => ['red', 'mustard'], 'pattern' => 'floral', 'style' => 'bohemian', 'season' => 'summer', 'decision' => ['rejected']],
            ['name' => 'عباية كتان مريمية', 'category' => 'عبايات', 'price' => 360, 'material' => 'linen', 'colors' => ['sage', 'ivory'], 'pattern' => 'solid', 'style' => 'minimal', 'season' => 'all_year', 'versions' => true],
            ['name' => 'طرحة شيفون وردي مطفي', 'category' => 'طرح', 'price' => 95, 'material' => 'chiffon', 'colors' => ['dusty_pink'], 'pattern' => 'solid', 'style' => 'modern', 'season' => 'eid_fitr', 'versions' => true],
            ['name' => 'جلابية تيراكوتا بنقشة السدو', 'category' => 'جلابيات', 'price' => 240, 'material' => 'cotton', 'colors' => ['terracotta', 'sand'], 'pattern' => 'sadu', 'style' => 'heritage', 'season' => 'ramadan', 'versions' => true],
            ['name' => 'عباية رياضية بخطوط', 'category' => 'عبايات', 'price' => 210, 'material' => 'chiffon', 'colors' => ['sky_blue', 'white'], 'pattern' => 'stripes', 'style' => 'sporty', 'season' => 'summer', 'decision' => ['approved', 'منتج تجريبي لشريحة الشابات قبل الصيف، كمية محدودة']],
            ['name' => 'عباية هندسية كحلي', 'category' => 'عبايات', 'price' => 395, 'material' => 'crepe', 'colors' => ['navy', 'gold'], 'pattern' => 'geometric', 'style' => 'modern', 'season' => 'winter'],
            ['name' => 'طرحة حرير ذهبية', 'category' => 'طرح', 'price' => 180, 'material' => 'silk', 'colors' => ['gold', 'ivory'], 'pattern' => 'arabesque', 'style' => 'luxury', 'season' => 'eid_fitr'],
            ['name' => 'جلابية لافندر مرحة', 'category' => 'جلابيات', 'price' => 390, 'material' => 'silk', 'colors' => ['lavender'], 'pattern' => 'abstract', 'style' => 'playful', 'season' => 'national_day', 'decision' => ['rejected']],
            ['name' => 'عباية عاجية بتطريز ذهبي', 'category' => 'عبايات', 'price' => 420, 'material' => 'crepe', 'colors' => ['ivory', 'gold'], 'pattern' => 'embroidery', 'style' => 'luxury', 'season' => 'ramadan'],
            ['name' => 'عباية بيج بسيطة للدوام', 'category' => 'عبايات', 'price' => 310, 'material' => 'linen', 'colors' => ['beige'], 'pattern' => 'solid', 'style' => 'minimal', 'season' => 'all_year'],
            ['name' => 'طرحة سدو تراثية', 'category' => 'طرح', 'price' => 120, 'material' => 'cotton', 'colors' => ['brown', 'sand'], 'pattern' => 'sadu', 'style' => 'heritage', 'season' => 'founding_day'],
            ['name' => 'جلابية زمردية للعيد', 'category' => 'جلابيات', 'price' => 230, 'material' => 'linen', 'colors' => ['emerald', 'gold'], 'pattern' => 'embroidery', 'style' => 'classic', 'season' => 'eid_fitr'],
            ['name' => 'عباية سوداء كلاسيكية', 'category' => 'عبايات', 'price' => 300, 'material' => 'crepe', 'colors' => ['black'], 'pattern' => 'solid', 'style' => 'classic', 'season' => 'all_year', 'versions' => true],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function giftsProfile(): array
    {
        return [
            'categories' => [
                'أكواب' => ['noun' => 'كوب', 'count' => [4, 4, 3], 'materials' => ['*' => ['ceramic', 'glass']]],
                'شموع' => ['noun' => 'شمعة', 'count' => [3, 3, 3], 'materials' => ['*' => ['glass', 'ceramic']]],
                'لوحات جدارية' => ['noun' => 'لوحة', 'count' => [3, 3, 2], 'materials' => ['*' => ['wood', 'paper', 'metal']]],
                'صناديق هدايا' => ['noun' => 'صندوق هدية', 'count' => [3, 2, 2], 'materials' => ['*' => ['wood', 'paper', 'leather']]],
            ],
            'dna' => [
                'win' => ['key' => 'win', 'colors' => ['gold', 'ivory', 'emerald', 'sand', 'black'], 'patterns' => ['calligraphy', 'arabesque', 'sadu'], 'styles' => ['heritage', 'luxury'], 'seasons' => ['ramadan', 'eid_fitr', 'all_year'],
                    'price' => ['*' => [65, 190]]],
                'mid' => ['key' => 'mid', 'colors' => ['white', 'grey', 'navy', 'brown', 'teal'], 'patterns' => ['geometric', 'solid'], 'styles' => ['modern', 'minimal', 'classic'], 'seasons' => ['all_year', 'winter'],
                    'price' => ['*' => [45, 220]]],
                'lose' => ['key' => 'lose', 'colors' => ['red', 'sky_blue', 'lavender', 'mustard'], 'patterns' => ['abstract', 'floral', 'stripes'], 'styles' => ['playful', 'bohemian'], 'seasons' => ['summer', 'back_to_school'],
                    'price' => ['*' => [240, 380]]],
            ],
            'seasonality' => [1 => 1.0, 2 => 1.8, 3 => 2.4, 4 => 0.9, 5 => 1.4, 6 => 0.8, 7 => 0.7, 8 => 0.8, 9 => 1.6, 10 => 0.9, 11 => 1.5, 12 => 1.3],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function giftsMarket(): array
    {
        return [
            'competitors' => [
                ['هدايا ديم', 'salla', 'https://deem-gifts.salla.sa', null],
                ['Saudi Crafts Co.', 'shopify', 'https://saudicrafts.myshopify.com', null],
                ['ركن الديكور', 'zid', 'https://rokn-decor.zid.store', null],
                ['ديكورات نجدية', 'instagram', 'https://instagram.com/najdi.decor', 'najdi.decor'],
                ['هدية وفكرة', 'tiktok', 'https://tiktok.com/@hadiya.fikra', 'hadiya.fikra'],
            ],
            'competitor_dna' => ['key' => 'mid', 'colors' => ['emerald', 'gold', 'sand', 'terracotta', 'sage'], 'patterns' => ['calligraphy', 'sadu', 'geometric'], 'styles' => ['heritage', 'modern', 'minimal'], 'seasons' => ['ramadan', 'founding_day', 'all_year']],
            'competitor_spec' => ['nouns' => ['أكواب' => 'كوب', 'شموع' => 'شمعة', 'لوحات جدارية' => 'لوحة'], 'materials' => ['*' => ['ceramic', 'wood', 'glass']]],
            'competitor_price' => [40, 260],
            'posts' => [
                'مجموعة أكواب بخط الثلث ☕', 'فوانيس رمضان بلمسة عصرية 🌙', 'لوحة سدو لمجلسكم', 'صندوق هدية يوم التأسيس', 'شموع بعطر العود',
                'ديكور زمردي فخم', 'هدايا تخرج مميزة', 'فكرة هدية للأم', 'مجموعة السدو الجديدة', 'تغليف هدايا العيد',
            ],
            'trends' => [
                ['pattern', 'calligraphy', 'الخط العربي', 'Arabic calligraphy', 79, 17.0, 62, 'rising', ['instagram', 'tiktok']],
                ['pattern', 'sadu', 'نقشة السدو', 'Sadu weave', 75, 38.0, 28, 'rising', ['instagram', 'snapchat']],
                ['color', 'emerald', 'زمردي', 'Emerald', 72, 24.0, 40, 'rising', ['instagram']],
                ['color', 'terracotta', 'تيراكوتا', 'Terracotta', 63, 19.0, 25, 'rising', ['tiktok']],
                ['color', 'gold', 'ذهبي', 'Gold', 58, 1.0, 82, 'stable', ['salla', 'zid']],
                ['pattern', 'abstract', 'تجريدي', 'Abstract', 30, -20.0, 70, 'declining', ['shopify']],
                ['style', 'heritage', 'تراثي معاصر', 'Contemporary heritage', 81, 29.0, 38, 'rising', ['instagram', 'tiktok', 'salla']],
                ['theme', 'founding_day', 'يوم التأسيس', 'Founding Day', 84, 61.0, 35, 'rising', ['instagram', 'snapchat']],
                ['theme', 'ramadan', 'رمضان', 'Ramadan', 90, 55.0, 58, 'rising', ['instagram', 'tiktok', 'salla']],
                ['material', 'wood', 'خشب طبيعي', 'Natural wood', 61, 12.0, 44, 'rising', ['shopify']],
            ],
            'reviews' => [
                ['الكوب مره فخم والخط واضح 👌', 5, 'positive', ['design', 'quality'], 'ar'],
                ['وصلني مكسور للأسف', 1, 'negative', ['packaging', 'delivery'], 'ar'],
                ['ريحة الشمعة خفيفة مره', 3, 'negative', ['quality'], 'ar'],
                ['هدية رائعة لوالدتي، شكراً', 5, 'positive', ['design'], 'ar'],
                ['السعر مناسب والتغليف حلو', 4, 'positive', ['price', 'packaging'], 'ar'],
                ['Lovely calligraphy mug, fast delivery', 5, null, null, 'en'],
                ['اللوحة أصغر من المتوقع', 2, null, null, 'ar'],
                ['ودي يكون فيه تخصيص بالاسم', 4, null, null, 'ar'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function giftsCandidates(): array
    {
        return [
            ['name' => 'كوب سيراميك بخط الثلث', 'category' => 'أكواب', 'price' => 79, 'material' => 'ceramic', 'colors' => ['emerald', 'gold'], 'pattern' => 'calligraphy', 'style' => 'heritage', 'season' => 'ramadan', 'decision' => ['approved']],
            ['name' => 'شمعة بنقشة السدو', 'category' => 'شموع', 'price' => 95, 'material' => 'glass', 'colors' => ['terracotta', 'sand'], 'pattern' => 'sadu', 'style' => 'heritage', 'season' => 'founding_day'],
            ['name' => 'لوحة تجريدية ملونة', 'category' => 'لوحات جدارية', 'price' => 340, 'material' => 'paper', 'colors' => ['red', 'sky_blue'], 'pattern' => 'abstract', 'style' => 'playful', 'season' => 'summer', 'decision' => ['rejected']],
            ['name' => 'صندوق هدية خشبي فاخر', 'category' => 'صناديق هدايا', 'price' => 210, 'material' => 'wood', 'colors' => ['brown', 'gold'], 'pattern' => 'arabesque', 'style' => 'luxury', 'season' => 'eid_fitr', 'versions' => true],
            ['name' => 'كوب زجاجي بسيط', 'category' => 'أكواب', 'price' => 45, 'material' => 'glass', 'colors' => ['white'], 'pattern' => 'solid', 'style' => 'minimal', 'season' => 'all_year'],
            ['name' => 'لوحة خط عربي زمردية', 'category' => 'لوحات جدارية', 'price' => 160, 'material' => 'wood', 'colors' => ['emerald', 'ivory'], 'pattern' => 'calligraphy', 'style' => 'heritage', 'season' => 'all_year'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function perfumeProfile(): array
    {
        return [
            'categories' => [
                'عطور' => ['noun' => 'عطر', 'count' => [3, 3, 2], 'materials' => ['*' => ['glass']]],
                'بخور' => ['noun' => 'بخور', 'count' => [2, 2, 2], 'materials' => ['*' => ['wood', 'metal']]],
            ],
            'dna' => [
                'win' => ['key' => 'win', 'colors' => ['gold', 'black', 'brown'], 'patterns' => ['arabesque', 'calligraphy'], 'styles' => ['luxury', 'heritage'], 'seasons' => ['ramadan', 'all_year'], 'price' => ['*' => [180, 420]]],
                'mid' => ['key' => 'mid', 'colors' => ['ivory', 'navy'], 'patterns' => ['solid', 'geometric'], 'styles' => ['classic', 'modern'], 'seasons' => ['all_year'], 'price' => ['*' => [120, 300]]],
                'lose' => ['key' => 'lose', 'colors' => ['dusty_pink', 'sky_blue'], 'patterns' => ['floral'], 'styles' => ['playful'], 'seasons' => ['summer'], 'price' => ['*' => [450, 700]]],
            ],
            'seasonality' => [2 => 1.7, 3 => 2.0, 5 => 1.4, 9 => 1.3, 12 => 1.2],
        ];
    }
}

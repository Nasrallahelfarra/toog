<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\Store;
use App\Services\Analytics\StorePerformance;
use App\Services\Zid\ZidStoreName;
use App\Support\Activity;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Store settings: general details, decision thresholds and weights
 * (FR-3.11, FR-5.2, FR-6.6) and the brand guide used by the design studio
 * (FR-4.6). Integration and imports live in IntegrationController.
 */
class SettingsController extends Controller
{
    public const CRITERIA = ['success_similarity', 'trend_fit', 'saudi_fit', 'failure_risk', 'saturation', 'price_fit'];

    public function __construct(private readonly CurrentStore $current) {}

    public function general(StorePerformance $performance): Response
    {
        $store = $this->current->require();

        return Inertia::render('store/settings/general', [
            'store' => [
                'name' => $store->name,
                // A Zid store's name follows Zid and is not edited here.
                'name_from_zid' => ZidStoreName::follows($store),
                'domain' => $store->domain,
                'category' => $store->category,
                'platform' => $store->platform,
                'slug' => $store->slug,
                'logo_url' => $store->logo_path ? Storage::disk('public')->url($store->logo_path) : null,
                'created_at' => $store->created_at?->toIso8601String(),
            ],
            'categoryNames' => $performance->categoryNames($store),
        ]);
    }

    public function updateGeneral(Request $request): RedirectResponse
    {
        $store = $this->current->require();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'domain' => ['nullable', 'string', 'max:190'],
            'category' => ['nullable', 'string', 'max:120'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);

        $before = $store->only(['name', 'domain', 'category', 'logo_path']);

        if ($request->hasFile('logo')) {
            $this->deleteLogo($store);
            $store->logo_path = $request->file('logo')->store("logos/{$store->id}", 'public');
        } elseif ($request->boolean('remove_logo')) {
            $this->deleteLogo($store);
            $store->logo_path = null;
        }

        $store->fill(Arr::only($data, ZidStoreName::follows($store) ? ['domain', 'category'] : ['name', 'domain', 'category']))->save();

        $this->logChanges($store, 'general', $before, $store->only(['name', 'domain', 'category', 'logo_path']));

        return $this->saved();
    }

    public function evaluation(): Response
    {
        $store = $this->current->require();

        return Inertia::render('store/settings/evaluation', [
            'values' => $this->evaluationValues($store),
            'defaults' => [
                'accept_threshold' => (int) PlatformSetting::storeDefault('accept_threshold'),
                'reject_threshold' => (int) PlatformSetting::storeDefault('reject_threshold'),
                'proven_ratio' => (int) PlatformSetting::storeDefault('proven_ratio'),
                'weights' => array_map('intval', PlatformSetting::storeDefault('weights')),
            ],
        ]);
    }

    public function updateEvaluation(Request $request): RedirectResponse
    {
        $store = $this->current->require();

        $rules = [
            'accept_threshold' => ['required', 'integer', 'min:40', 'max:95'],
            'reject_threshold' => ['required', 'integer', 'min:10', 'max:85'],
            'proven_ratio' => ['required', 'integer', 'min:40', 'max:95'],
            'weights' => ['required', 'array'],
        ];
        foreach (self::CRITERIA as $criterion) {
            $rules["weights.{$criterion}"] = ['required', 'integer', 'min:0', 'max:100'];
        }

        $data = $request->validate($rules);

        if ($data['accept_threshold'] <= $data['reject_threshold']) {
            throw ValidationException::withMessages(['accept_threshold' => __('store.thresholds_order')]);
        }

        if (array_sum($data['weights']) <= 0) {
            throw ValidationException::withMessages(['weights' => __('store.weights_required')]);
        }

        $before = $this->evaluationValues($store);

        $store->settings = array_merge($store->settings ?? [], [
            'accept_threshold' => $data['accept_threshold'],
            'reject_threshold' => $data['reject_threshold'],
            'proven_ratio' => $data['proven_ratio'],
            'weights' => Arr::only($data['weights'], self::CRITERIA),
        ]);
        $store->save();

        $this->logChanges($store, 'evaluation', Arr::dot($before), Arr::dot($this->evaluationValues($store)));

        return $this->saved();
    }

    public function brand(): Response
    {
        $store = $this->current->require();
        $guide = $store->brand_guide ?? [];

        return Inertia::render('store/settings/brand', [
            'guide' => [
                'colors' => array_values($guide['colors'] ?? []),
                'fonts' => array_values($guide['fonts'] ?? []),
                'style_ar' => $guide['style_ar'] ?? '',
                'style_en' => $guide['style_en'] ?? '',
                'avoid_ar' => $guide['avoid_ar'] ?? '',
                'avoid_en' => $guide['avoid_en'] ?? '',
            ],
            'store' => [
                'name' => $store->name,
                'logo_url' => $store->logo_path ? Storage::disk('public')->url($store->logo_path) : null,
            ],
        ]);
    }

    public function updateBrand(Request $request): RedirectResponse
    {
        $store = $this->current->require();

        $data = $request->validate([
            'colors' => ['present', 'array', 'max:10'],
            'colors.*' => ['string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'fonts' => ['present', 'array', 'max:5'],
            'fonts.*' => ['string', 'max:60'],
            'style_ar' => ['nullable', 'string', 'max:1000'],
            'style_en' => ['nullable', 'string', 'max:1000'],
            'avoid_ar' => ['nullable', 'string', 'max:1000'],
            'avoid_en' => ['nullable', 'string', 'max:1000'],
        ]);

        $before = $store->brand_guide ?? [];

        $store->brand_guide = array_merge($before, [
            'colors' => array_values(array_unique(array_map('strtoupper', $data['colors']))),
            'fonts' => array_values(array_filter(array_map('trim', $data['fonts']))),
            'style_ar' => $data['style_ar'] ?? '',
            'style_en' => $data['style_en'] ?? '',
            'avoid_ar' => $data['avoid_ar'] ?? '',
            'avoid_en' => $data['avoid_en'] ?? '',
        ]);
        $store->save();

        $this->logChanges($store, 'brand', Arr::dot($before), Arr::dot($store->brand_guide));

        return $this->saved();
    }

    /**
     * @return array{accept_threshold: int, reject_threshold: int, proven_ratio: int, weights: array<string, int>}
     */
    private function evaluationValues(Store $store): array
    {
        $weights = array_merge(PlatformSetting::storeDefault('weights'), $store->settings['weights'] ?? []);

        return [
            'accept_threshold' => (int) $store->setting('accept_threshold'),
            'reject_threshold' => (int) $store->setting('reject_threshold'),
            'proven_ratio' => (int) $store->setting('proven_ratio'),
            'weights' => array_map('intval', Arr::only($weights, self::CRITERIA)),
        ];
    }

    /**
     * Record only the fields that actually changed (FR-6.7).
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function logChanges(Store $store, string $section, array $before, array $after): void
    {
        $changes = [];

        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) != $value) {
                $changes[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
            }
        }

        if ($changes) {
            Activity::log('settings.updated', $store, ['section' => $section, 'changes' => $changes]);
        }
    }

    private function deleteLogo(Store $store): void
    {
        if ($store->logo_path) {
            Storage::disk('public')->delete($store->logo_path);
        }
    }

    private function saved(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.saved')]);

        return back();
    }
}

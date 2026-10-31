<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\SeasonalEvent;
use App\Services\Ai\Ai;
use App\Support\Activity;
use App\Support\Vocabulary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    private const WEIGHT_KEYS = ['success_similarity', 'trend_fit', 'saudi_fit', 'failure_risk', 'saturation', 'price_fit'];

    public function edit(Ai $ai): Response
    {
        $gemini = config('mojo.ai.gemini');

        return Inertia::render('admin/settings', [
            'defaults' => $this->defaults(),
            'saudiPalette' => Vocabulary::saudiPalette(),
            'ai' => [
                'provider' => config('mojo.ai.provider'),
                'live' => $ai->isLive(),
                'key_configured' => filled($gemini['api_key']),
                'text_model' => $gemini['text_model'],
                'image_model' => $gemini['image_model'],
                'pricing' => collect(config('mojo.ai.pricing'))
                    ->map(fn (array $p, string $model) => ['model' => $model, ...$p])
                    ->values(),
            ],
            'events' => SeasonalEvent::query()->whereNull('store_id')->orderBy('starts_on')->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $colors = array_keys(Vocabulary::colors());

        $data = $request->validate([
            'accept_threshold' => ['required', 'integer', 'min:50', 'max:100'],
            'reject_threshold' => ['required', 'integer', 'min:0', 'lt:accept_threshold'],
            'proven_ratio' => ['required', 'integer', 'min:50', 'max:95'],
            'sync_frequency' => ['required', Rule::in(['daily', 'weekly'])],
            'weights' => ['required', 'array'],
            ...collect(self::WEIGHT_KEYS)->mapWithKeys(fn ($k) => ["weights.{$k}" => ['required', 'integer', 'min:0', 'max:100']])->all(),
            'saudi_palette' => ['required', 'array', 'min:1'],
            'saudi_palette.*' => ['string', Rule::in($colors)],
        ]);

        if (array_sum($data['weights']) <= 0) {
            return back()->withErrors(['weights' => __('mojo.weights_required')]);
        }

        $before = [...$this->defaults(), 'saudi_palette' => Vocabulary::saudiPalette()];

        PlatformSetting::put('store_defaults', [
            'accept_threshold' => (int) $data['accept_threshold'],
            'reject_threshold' => (int) $data['reject_threshold'],
            'proven_ratio' => (int) $data['proven_ratio'],
            'sync_frequency' => $data['sync_frequency'],
            'weights' => collect(self::WEIGHT_KEYS)->mapWithKeys(fn ($k) => [$k => (int) $data['weights'][$k]])->all(),
        ]);
        PlatformSetting::put('saudi_palette', array_values(array_unique($data['saudi_palette'])));

        $after = [...$this->defaults(), 'saudi_palette' => Vocabulary::saudiPalette()];
        $changes = collect($after)
            ->filter(fn ($value, $key) => $before[$key] != $value)
            ->map(fn ($value, $key) => ['from' => $before[$key], 'to' => $value])
            ->all();

        Activity::log('admin.settings_updated', null, ['changes' => $changes]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.saved')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'accept_threshold' => (int) PlatformSetting::storeDefault('accept_threshold'),
            'reject_threshold' => (int) PlatformSetting::storeDefault('reject_threshold'),
            'proven_ratio' => (int) PlatformSetting::storeDefault('proven_ratio'),
            'sync_frequency' => (string) PlatformSetting::storeDefault('sync_frequency'),
            'weights' => collect(self::WEIGHT_KEYS)->mapWithKeys(fn ($k) => [$k => (int) PlatformSetting::storeDefault("weights.{$k}")])->all(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\DesignerOption;
use App\Models\Product;
use App\Support\Activity;
use App\Support\CurrentStore;
use App\Support\FashionVocabulary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The merchant's own fabrics and sections in the AI designer: added from
 * the brief, kept for the store, removable.
 */
class DesignerOptionController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    public function store(Request $request): RedirectResponse
    {
        $store = $this->current->require();
        $request->merge(['label' => trim(preg_replace('/\s+/u', ' ', (string) $request->input('label')) ?? '')]);

        $data = $request->validate([
            'type' => ['required', Rule::in([DesignerOption::FABRIC, DesignerOption::SECTION])],
            // At least two Arabic or Latin letters.
            'label' => ['required', 'string', 'min:2', 'max:40', 'regex:/[\p{Arabic}A-Za-z].*[\p{Arabic}A-Za-z]/u'],
            'garment' => ['nullable', 'required_if:type,'.DesignerOption::SECTION, Rule::in(array_keys(FashionVocabulary::garments()))],
        ], [
            'label.required' => __('mojo.designer_option_label_required'),
            'label.min' => __('mojo.designer_option_label_short'),
            'label.max' => __('mojo.designer_option_label_long'),
            'label.regex' => __('mojo.designer_option_letters'),
            'garment.required_if' => __('mojo.designer_option_garment_required'),
        ]);

        $type = $data['type'];
        $label = $data['label'];
        $normalized = DesignerOption::normalize($label);
        $own = DesignerOption::query()->where('store_id', $store->id)->where('type', $type)->get();

        // The same name as a built-in fabric, a Zid section or one already added.
        $builtIn = $type === DesignerOption::FABRIC
            ? collect(FashionVocabulary::fabrics())->flatMap(fn (array $f) => [$f['ar'], $f['en']])
            : Product::query()->where('store_id', $store->id)->whereNotNull('category')->distinct()->pluck('category');
        if ($builtIn->concat($own->pluck('label'))->contains(fn ($name) => DesignerOption::normalize((string) $name) === $normalized)) {
            throw ValidationException::withMessages(['label' => __('mojo.designer_option_exists', ['name' => $label])]);
        }
        if ($own->count() >= DesignerOption::LIMITS[$type]) {
            throw ValidationException::withMessages(['label' => __('mojo.designer_option_limit', ['count' => DesignerOption::LIMITS[$type]])]);
        }

        $option = DesignerOption::query()->create([
            'store_id' => $store->id,
            'type' => $type,
            'label' => $label,
            'normalized' => $normalized,
            'garment' => $type === DesignerOption::SECTION ? $data['garment'] : null,
        ]);

        Activity::log('designer.option_added', $option, ['type' => $type, 'label' => $label]);

        return back();
    }

    /**
     * Removing one that is already gone (another tab, a double click) is
     * not an error; only the store's own options are ever found.
     */
    public function destroy(int $option): RedirectResponse
    {
        $found = DesignerOption::query()->where('store_id', $this->current->id())->find($option);

        if ($found) {
            Activity::log('designer.option_removed', $found, ['type' => $found->type, 'label' => $found->label]);
            $found->delete();
        }

        return back();
    }

    /**
     * The store's own fabrics and sections for the designer's brief. A
     * custom fabric carries the built-in fabric its name points to, for
     * drape and sheerness ("شيفون مطرز" behaves like chiffon).
     *
     * @return array{fabrics: list<array{id: int, key: string, label: string, base: string|null}>, sections: list<array{id: int, label: string, garment: string}>}
     */
    public static function forBrief(int $storeId): array
    {
        $options = DesignerOption::query()->where('store_id', $storeId)->orderBy('id')->get();

        return [
            'fabrics' => $options->where('type', DesignerOption::FABRIC)
                ->map(fn (DesignerOption $o) => ['id' => $o->id, 'key' => "custom:{$o->id}", 'label' => $o->label, 'base' => self::fabricBase($o->label)])
                ->values()->all(),
            'sections' => $options->where('type', DesignerOption::SECTION)->filter(fn (DesignerOption $o) => isset(FashionVocabulary::garments()[(string) $o->garment]))
                ->map(fn (DesignerOption $o) => ['id' => $o->id, 'label' => $o->label, 'garment' => (string) $o->garment])->values()->all(),
        ];
    }

    /**
     * The built-in fabric a custom fabric's name contains, longest name
     * first ("كريب جورجيت" is georgette crepe before crepe), or null.
     */
    public static function fabricBase(string $label): ?string
    {
        $name = ' '.DesignerOption::normalize($label).' ';

        return collect(FashionVocabulary::fabrics())
            ->flatMap(fn (array $f, string $key) => [[$key, DesignerOption::normalize($f['ar'])], [$key, DesignerOption::normalize($f['en'])]])
            ->filter(fn (array $pair) => mb_strlen($pair[1]) >= 3 && str_contains($name, $pair[1]))
            ->sortByDesc(fn (array $pair) => mb_strlen($pair[1]))
            ->first()[0] ?? null;
    }
}

<?php

namespace App\Http\Requests\Store;

use App\Support\Vocabulary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A candidate product for the quality gate (FR-3.1).
 */
class StoreEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:160'],
            'category' => ['nullable', 'string', 'max:80'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'material' => ['nullable', Rule::in(array_keys(Vocabulary::materials()))],
            'pattern' => ['nullable', Rule::in(array_keys(Vocabulary::patterns()))],
            'style' => ['nullable', Rule::in(array_keys(Vocabulary::styles()))],
            'season' => ['nullable', Rule::in(array_keys(Vocabulary::seasons()))],
            'colors' => ['nullable', 'array', 'max:4'],
            'colors.*' => [Rule::in(array_keys(Vocabulary::colors()))],
            'description' => ['nullable', 'string', 'max:2000'],
            'target_audience' => ['nullable', 'string', 'max:1000'],
            'image' => ['nullable', 'image', 'max:8192'],
        ];
    }
}

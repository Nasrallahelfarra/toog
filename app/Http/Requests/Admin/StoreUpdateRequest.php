<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'domain' => ['nullable', 'string', 'max:190'],
            'category' => ['nullable', 'string', 'max:120'],
            'sync_frequency' => ['required', Rule::in(['daily', 'weekly'])],
            'ai_monthly_budget' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'accept_threshold' => ['required', 'integer', 'min:50', 'max:100'],
            'reject_threshold' => ['required', 'integer', 'min:0', 'lt:accept_threshold'],
            'proven_ratio' => ['required', 'integer', 'min:50', 'max:95'],
        ];
    }
}

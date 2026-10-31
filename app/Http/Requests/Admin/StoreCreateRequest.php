<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCreateRequest extends FormRequest
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
        $new = $this->input('owner_mode') !== 'existing';

        return [
            'name' => ['required', 'string', 'max:120'],
            'domain' => ['nullable', 'string', 'max:190'],
            'category' => ['nullable', 'string', 'max:120'],
            // Only Zid is integrated for now.
            'platform' => ['required', Rule::in(['zid'])],
            'ai_monthly_budget' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'owner_mode' => ['required', Rule::in(['new', 'existing'])],
            'owner_name' => [$new ? 'required' : 'nullable', 'string', 'max:120'],
            'owner_email' => $new
                ? ['required', 'email', 'max:190', Rule::unique(User::class, 'email')]
                : ['required', 'email', Rule::exists(User::class, 'email')],
            'owner_phone' => ['nullable', 'string', 'max:30'],
            'owner_password' => [$new ? 'required' : 'nullable', 'string', 'min:8', 'max:120'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->input('owner_mode') !== 'existing') {
                    return;
                }

                $owner = User::query()->where('email', $this->input('owner_email'))->first();

                if ($owner && $owner->isAdmin()) {
                    $validator->errors()->add('owner_email', __('mojo.owner_must_be_store_owner'));
                }
            },
        ];
    }
}

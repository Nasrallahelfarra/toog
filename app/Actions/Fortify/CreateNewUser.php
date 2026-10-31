<?php

namespace App\Actions\Fortify;

use App\Actions\Stores\CreateStore;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Register a store owner together with their store.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'store_name' => ['required', 'string', 'max:120'],
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'role' => User::ROLE_STORE_OWNER,
                'locale' => app()->getLocale(),
            ]);

            app(CreateStore::class)->handle($user, ['name' => $input['store_name']]);

            return $user;
        });
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(['email' => 'admin@mojo.ai'], [
            'name' => 'مدير المنصة',
            'password' => 'password',
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);

        $this->call([
            SeasonalEventSeeder::class,
            DemoStoresSeeder::class,
        ]);
    }
}

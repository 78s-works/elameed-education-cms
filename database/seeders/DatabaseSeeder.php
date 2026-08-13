<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Minimal seeder: a single platform-admin account, nothing else.
 *
 * Re-runnable: the admin is matched by email, so `php artisan db:seed`
 * always yields exactly one admin row (no duplicates, no demo data).
 *
 * Login with email `admin@elameed.app` (or phone `01000000000`) and the
 * password `password`. The `hashed` cast hashes the password on set.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrNew(['email' => 'admin@elameed.app']);

        $admin->forceFill([
            'name' => 'إدارة منصة العميد',
            'phone' => '01000000000',
            'password' => 'password',
            'locale' => 'ar',
            'is_platform_admin' => true,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'remember_token' => $admin->remember_token ?? Str::random(10),
        ])->save();

        $this->command?->info('Seeded platform admin: admin@elameed.app / password');
    }
}

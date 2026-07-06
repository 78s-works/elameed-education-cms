<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // $this->call([
        //     TenantSeeder::class,
        // ]);

        // Platform administrator (operates the whole SaaS). Logs in on the
        // platform host (no tenant) and provisions teachers via /admin/tenants.
        User::firstOrCreate(
            ['phone' => '01000000009'],
            [
                'name' => 'Platform Admin',
                'email' => 'admin@elameed.test',
                'password' => 'password',
                'phone_verified_at' => now(),
                'is_platform_admin' => true,
            ],
        );
    }
}

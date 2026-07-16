<?php

namespace Database\Seeders;

use App\Modules\Billing\Models\SubscriptionPackage;
use Illuminate\Database\Seeder;

/**
 * Seeds the default teacher subscription plans (M03). Idempotent — safe to
 * re-run. Prices are indicative EGP minor units (piastres).
 */
class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'description' => 'For a teacher just getting started.',
                'price_minor' => 0,
                'interval' => 'monthly',
                'trial_days' => 0,
                'sort_order' => 1,
                'limits' => ['max_students' => 100, 'max_courses' => 3, 'storage_mb' => 5000, 'max_assistants' => 0],
            ],
            [
                'slug' => 'growth',
                'name' => 'Growth',
                'description' => 'For a growing academy with multiple courses.',
                'price_minor' => 150000, // 1,500.00 EGP / month
                'interval' => 'monthly',
                'trial_days' => 14,
                'sort_order' => 2,
                'limits' => ['max_students' => 2000, 'max_courses' => 30, 'storage_mb' => 50000, 'max_assistants' => 3],
            ],
            [
                'slug' => 'scale',
                'name' => 'Scale',
                'description' => 'Unlimited students and courses for large academies.',
                'price_minor' => 500000, // 5,000.00 EGP / month
                'interval' => 'monthly',
                'trial_days' => 14,
                'sort_order' => 3,
                'limits' => ['max_students' => null, 'max_courses' => null, 'storage_mb' => 500000, 'max_assistants' => 10],
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPackage::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}

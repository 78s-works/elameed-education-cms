<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantDomainType;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use Illuminate\Database\Seeder;

/**
 * Seeds one demo tenant reachable at demo.<base_domain> (resolves via the
 * subdomain OR the `X-Tenant: demo` header), plus a demo teacher account and a
 * branded profile so the landing/context has something to render.
 */
class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'demo'],
            ['name' => 'Demo Academy', 'status' => TenantStatus::Active],
        );

        TenantDomain::firstOrCreate(
            ['host' => 'demo.'.config('tenancy.base_domain', 'elameed.app')],
            [
                'tenant_id' => $tenant->id,
                'type' => TenantDomainType::Subdomain,
                'is_primary' => true,
            ],
        );

        $teacher = User::firstOrCreate(
            ['phone' => '01000000000'],
            [
                'name' => 'Demo Teacher',
                'email' => 'teacher@demo.test',
                'password' => 'password',
                'locale' => 'ar',
                'phone_verified_at' => now(),
            ],
        );

        // Make the teacher the tenant owner + an active teacher member.
        $tenant->forceFill(['owner_user_id' => $teacher->id])->save();

        TenantUser::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $teacher->id, 'role' => TenantUserRole::Teacher->value],
            ['status' => MembershipStatus::Active->value, 'joined_at' => now()],
        );

        // Demo student — an active member of this academy (for quick login testing).
        $student = User::firstOrCreate(
            ['phone' => '01000000001'],
            [
                'name' => 'Demo Student',
                'email' => 'student@demo.test',
                'password' => 'password',
                'locale' => 'ar',
                'phone_verified_at' => now(),
            ],
        );

        TenantUser::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $student->id, 'role' => TenantUserRole::Student->value],
            ['status' => MembershipStatus::Active->value, 'joined_at' => now()],
        );

        // Branded profile (tenant_id set explicitly — no request tenant context here).
        if (! TeacherProfile::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
            $profile = new TeacherProfile([
                'primary_color' => '#1D4ED8',
                'secondary_color' => '#9333EA',
                'bio' => 'أكاديمية تجريبية', // "Demo academy"
                'socials' => ['youtube' => 'https://youtube.com/@demo'],
                'landing_sections' => [
                    ['key' => 'courses', 'visible' => true],
                    ['key' => 'about', 'visible' => true],
                    ['key' => 'testimonials', 'visible' => false],
                ],
            ]);
            $profile->tenant_id = $tenant->id;
            $profile->save();
        }
    }
}

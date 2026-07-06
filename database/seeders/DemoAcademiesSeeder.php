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
 * Demo helper: two independent academies (tenants), each with its own teacher +
 * student, to show multi-tenant isolation. Idempotent. Not part of the default
 * seed — run on demand:
 *
 *   php artisan db:seed --class=DemoAcademiesSeeder
 */
class DemoAcademiesSeeder extends Seeder
{
    public function run(): void
    {
        $this->academy('ahmed', 'Mr Ahmed Academy', '#2563EB', '01111100001', '01111100002');
        $this->academy('mona', 'Ms Mona Academy', '#DC2626', '01222200001', '01222200002');
    }

    private function academy(string $slug, string $name, string $primary, string $teacherPhone, string $studentPhone): void
    {
        $tenant = Tenant::firstOrCreate(['slug' => $slug], ['name' => $name, 'status' => TenantStatus::Active->value]);

        TenantDomain::firstOrCreate(
            ['host' => $slug.'.'.config('tenancy.base_domain', 'elameed.app')],
            ['tenant_id' => $tenant->id, 'type' => TenantDomainType::Subdomain->value, 'is_primary' => true],
        );

        $teacher = User::firstOrCreate(['phone' => $teacherPhone], [
            'name' => $name.' — Teacher',
            'email' => $slug.'-teacher@demo.test',
            'password' => 'password',
            'phone_verified_at' => now(),
        ]);
        $tenant->forceFill(['owner_user_id' => $teacher->id])->save();
        TenantUser::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $teacher->id, 'role' => TenantUserRole::Teacher->value],
            ['status' => MembershipStatus::Active->value, 'joined_at' => now()],
        );

        $student = User::firstOrCreate(['phone' => $studentPhone], [
            'name' => $name.' — Student',
            'email' => $slug.'-student@demo.test',
            'password' => 'password',
            'phone_verified_at' => now(),
        ]);
        TenantUser::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $student->id, 'role' => TenantUserRole::Student->value],
            ['status' => MembershipStatus::Active->value, 'joined_at' => now()],
        );

        if (! TeacherProfile::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
            $profile = new TeacherProfile(['primary_color' => $primary, 'bio' => $name]);
            $profile->tenant_id = $tenant->id;
            $profile->save();
        }
    }
}

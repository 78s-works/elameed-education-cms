<?php

namespace App\Modules\Identity\Http\Controllers\Teacher\Concerns;

use App\Models\User;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;

/**
 * Shared helpers for teacher endpoints that act on one of THEIR students.
 * Guarantees the target user is actually a student of the current tenant —
 * otherwise 404 (so a user from another academy is invisible, not "forbidden").
 */
trait ManagesTenantStudents
{
    /** The student's membership row in this tenant, or 404. */
    protected function membershipOrFail(int $tenantId, User $student): TenantUser
    {
        $membership = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $student->getKey())
            ->where('role', TenantUserRole::Student->value)
            ->first();

        abort_if($membership === null, 404, 'Student not found in this academy.');

        $this->assertStudentYearInScope($tenantId, $student);

        return $membership;
    }

    /**
     * A year-scoped member (an assistant hired for specific years) may only reach
     * students pinned to one of those years — M20's "authority vs scope" split:
     * the role says what they may do, their assigned years say who they may do it
     * to. Without this, a uuid guessed or kept from an old link would open another
     * year's student, with every sub-resource (wallet, orders, activity) behind it.
     *
     * 404, not 403, to match the membership check above: an out-of-scope student
     * is simply invisible.
     *
     * A member with NO assigned years is unscoped (the academy owner), and a
     * student with no pinned year can't be placed, so both fall through.
     */
    protected function assertStudentYearInScope(int $tenantId, User $student): void
    {
        $assigned = $this->assignedYearIds($tenantId);

        if ($assigned === []) {
            return;
        }

        $studentYearId = StudentProfile::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $student->getKey())
            ->value('academic_year_id');

        if ($studentYearId === null) {
            return;
        }

        abort_if(! in_array((int) $studentYearId, $assigned, true), 404, 'Student not found in this academy.');
    }

    /**
     * The academic years the CALLER's membership is confined to, or [] when they
     * are unscoped.
     *
     * @return array<int, int>
     */
    protected function assignedYearIds(int $tenantId): array
    {
        $user = request()->user();

        if ($user === null) {
            return [];
        }

        $tenant = app(TenantContext::class)->tenant();
        $membership = $tenant instanceof Tenant ? $user->membershipFor($tenant) : null;

        if ($membership === null) {
            return [];
        }

        return $membership->academicYears()
            ->pluck('academic_years.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}

<?php

namespace App\Modules\Notifications\Services\Broadcasts;

use App\Modules\Notifications\Enums\BroadcastAudience;
use Illuminate\Support\Facades\DB;

/**
 * Turns a broadcast audience (+ its ids) into the concrete user ids to deliver
 * to. Every academy-scoped audience is filtered by `tenant_id` here rather than
 * relying on the caller, so a hand-picked student list from another academy
 * silently resolves to nobody instead of leaking a message across tenants.
 *
 * Only ACTIVE memberships are addressed: a pending or suspended student is not
 * part of any audience.
 */
class AudienceResolver
{
    /**
     * Table each audience's `audience_ids` address, for uuid → id translation.
     * The API is uuid-facing (that is what the SPA's resources expose), but the
     * audience queries join on numeric keys.
     */
    private const UUID_TABLES = [
        BroadcastAudience::Package->value => 'packages',
        BroadcastAudience::AcademicYear->value => 'academic_years',
        BroadcastAudience::Center->value => 'centers',
        BroadcastAudience::Students->value => 'users',
        // `lessons` has no uuid column — lessons are addressed by numeric id,
        // which is what LessonResource exposes.
    ];

    /**
     * @param  array<int, int|string>  $audienceIds  numeric ids or uuids
     * @return list<int> distinct user ids
     */
    public function resolve(BroadcastAudience $audience, ?int $tenantId, array $audienceIds = []): array
    {
        $ids = $this->normalizeIds($audience, $tenantId, $audienceIds);

        return match ($audience) {
            BroadcastAudience::AllStudents => $this->members($tenantId, 'student'),
            BroadcastAudience::Assistants => $this->members($tenantId, 'assistant'),
            BroadcastAudience::Teachers => $this->platformTeachers(),
            BroadcastAudience::Students => $this->pickedStudents($tenantId, $ids),
            BroadcastAudience::AcademicYear => $this->byProfileColumn($tenantId, 'academic_year_id', $ids),
            BroadcastAudience::Center => $this->byProfileColumn($tenantId, 'center_id', $ids),
            BroadcastAudience::Lesson => $this->byEnrollment($tenantId, 'lesson_id', $ids),
            BroadcastAudience::Package => $this->byEnrollment($tenantId, 'package_id', $ids),
        };
    }

    /**
     * Turn whatever the client sent — numeric ids, uuids, or a mix — into
     * numeric ids. A uuid that belongs to another academy resolves to nothing,
     * so a copied uuid cannot address a foreign audience.
     *
     * @param  array<int, int|string>  $raw
     * @return list<int>
     */
    private function normalizeIds(BroadcastAudience $audience, ?int $tenantId, array $raw): array
    {
        $numeric = [];
        $uuids = [];

        foreach ($raw as $value) {
            $value = is_string($value) ? trim($value) : $value;

            if ($value === '' || $value === null) {
                continue;
            }

            if (is_int($value) || ctype_digit((string) $value)) {
                $numeric[] = (int) $value;

                continue;
            }

            $uuids[] = (string) $value;
        }

        $table = self::UUID_TABLES[$audience->value] ?? null;

        if ($uuids !== [] && $table !== null) {
            $query = DB::table($table)->whereIn('uuid', $uuids);

            // `users` is global (a person can belong to several academies), so
            // it is filtered by membership in the audience query instead.
            if ($table !== 'users' && $tenantId !== null) {
                $query->where('tenant_id', $tenantId);
            }

            foreach ($query->pluck('id') as $id) {
                $numeric[] = (int) $id;
            }
        }

        return array_values(array_unique(array_filter($numeric)));
    }

    /** Active members of one academy holding the given membership role. @return list<int> */
    private function members(?int $tenantId, string $role): array
    {
        if ($tenantId === null) {
            return [];
        }

        return DB::table('tenant_user')
            ->where('tenant_id', $tenantId)
            ->where('role', $role)
            ->where('status', 'active')
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The owner account of every academy — the audience for an admin broadcast.
     *
     * @return list<int>
     */
    private function platformTeachers(): array
    {
        return DB::table('tenant_user')
            ->where('role', 'teacher')
            ->where('status', 'active')
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Hand-picked students, intersected with this academy's active students so a
     * foreign or inactive id cannot be addressed.
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    private function pickedStudents(?int $tenantId, array $userIds): array
    {
        if ($tenantId === null || $userIds === []) {
            return [];
        }

        return DB::table('tenant_user')
            ->where('tenant_id', $tenantId)
            ->where('role', 'student')
            ->where('status', 'active')
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Students whose profile points at one of the given grades / centers.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function byProfileColumn(?int $tenantId, string $column, array $ids): array
    {
        if ($tenantId === null || $ids === []) {
            return [];
        }

        return DB::table('student_profiles')
            ->join('tenant_user', function ($join) use ($tenantId): void {
                $join->on('tenant_user.user_id', '=', 'student_profiles.user_id')
                    ->where('tenant_user.tenant_id', '=', $tenantId)
                    ->where('tenant_user.role', '=', 'student')
                    ->where('tenant_user.status', '=', 'active');
            })
            ->where('student_profiles.tenant_id', $tenantId)
            ->whereIn('student_profiles.'.$column, $ids)
            ->pluck('student_profiles.user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Students holding an ACTIVE enrollment on the given lesson(s) / package(s).
     * An expired window is not an audience: `expires_at` in the past is skipped.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function byEnrollment(?int $tenantId, string $column, array $ids): array
    {
        if ($tenantId === null || $ids === []) {
            return [];
        }

        return DB::table('enrollments')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereIn($column, $ids)
            ->where(function ($q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}

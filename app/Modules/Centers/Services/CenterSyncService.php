<?php

namespace App\Modules\Centers\Services;

use App\Models\User;
use App\Modules\Centers\Enums\CodeStatus;
use App\Modules\Centers\Models\ActivationCode;
use App\Modules\Centers\Models\AttendanceRecord;
use App\Modules\Centers\Models\Center;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Applies a batch of offline center-app events (M12) idempotently. Each event
 * carries a client `external_ref`: attendance is deduped on it (+ the one-per-day
 * unique key); redemptions rely on the code's one-time status. Returns a per-item
 * result (`applied` | `duplicate` | `failed`) so the app can reconcile its queue.
 */
class CenterSyncService
{
    public function __construct(private readonly CodeRedemptionService $redemption) {}

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    public function handle(int $tenantId, array $events, int $markedBy): array
    {
        $results = [];

        foreach ($events as $e) {
            $ref = (string) $e['external_ref'];
            try {
                $results[] = $e['kind'] === 'attendance'
                    ? $this->attendance($tenantId, $e, $ref, $markedBy)
                    : $this->redeemEvent($tenantId, $e, $ref);
            } catch (Throwable) {
                $results[] = ['external_ref' => $ref, 'kind' => $e['kind'], 'status' => 'failed', 'message' => 'Could not process event.'];
            }
        }

        return $results;
    }

    private function attendance(int $tenantId, array $e, string $ref, int $markedBy): array
    {
        if (AttendanceRecord::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('external_ref', $ref)->exists()) {
            return $this->ok($ref, 'attendance', 'duplicate');
        }

        $center = Center::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('uuid', $e['center_uuid'] ?? '')->first();
        if ($center === null) {
            return $this->fail($ref, 'attendance', 'Unknown center.');
        }

        $student = $this->resolveStudent($tenantId, $e);
        if ($student === null) {
            return $this->fail($ref, 'attendance', 'Unknown student.');
        }

        try {
            $record = new AttendanceRecord([
                'center_id' => $center->id,
                'user_id' => $student->id,
                'attended_on' => $e['attended_on'] ?? now()->toDateString(),
                'status' => $e['status'] ?? 'present',
                'marked_by' => $markedBy,
                'source' => 'offline',
                'external_ref' => $ref,
            ]);
            $record->tenant_id = $tenantId;
            $record->save();
        } catch (QueryException) {
            return $this->ok($ref, 'attendance', 'duplicate'); // already marked that day (unique key)
        }

        return $this->ok($ref, 'attendance', 'applied');
    }

    private function redeemEvent(int $tenantId, array $e, string $ref): array
    {
        $student = $this->resolveStudent($tenantId, $e);
        if ($student === null) {
            return $this->fail($ref, 'redeem', 'Unknown student.');
        }
        $code = $e['code'] ?? null;
        if (! is_string($code) || $code === '') {
            return $this->fail($ref, 'redeem', 'Missing code.');
        }

        try {
            return $this->ok($ref, 'redeem', 'applied') + ['grant' => $this->redemption->redeem($tenantId, $code, $student)];
        } catch (ValidationException $v) {
            $ac = ActivationCode::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('code', $code)->first();
            if ($ac !== null && $ac->status === CodeStatus::Redeemed && (int) $ac->redeemed_by === $student->id) {
                return $this->ok($ref, 'redeem', 'duplicate'); // same student already redeemed it
            }

            return $this->fail($ref, 'redeem', collect($v->errors())->flatten()->first() ?? 'Redemption failed.');
        }
    }

    private function resolveStudent(int $tenantId, array $e): ?User
    {
        $user = null;
        if (! empty($e['student_uuid'])) {
            $user = User::query()->where('uuid', $e['student_uuid'])->first();
        }
        if ($user === null && ! empty($e['student_phone'])) {
            $user = User::query()->where('phone', $e['student_phone'])->first();
        }
        if ($user === null) {
            return null;
        }

        $isMember = TenantUser::query()
            ->where('tenant_id', $tenantId)->where('user_id', $user->id)
            ->where('role', TenantUserRole::Student->value)->exists();

        return $isMember ? $user : null;
    }

    private function ok(string $ref, string $kind, string $status): array
    {
        return ['external_ref' => $ref, 'kind' => $kind, 'status' => $status];
    }

    private function fail(string $ref, string $kind, string $message): array
    {
        return ['external_ref' => $ref, 'kind' => $kind, 'status' => 'failed', 'message' => $message];
    }
}

<?php

namespace App\Modules\Centers\Http\Controllers\Teacher;

use App\Models\User;
use App\Modules\Centers\Http\Requests\CheckinAttendanceRequest;
use App\Modules\Centers\Http\Resources\SessionAttendanceResource;
use App\Modules\Centers\Models\AttendanceRecord;
use App\Modules\Centers\Models\Center;
use App\Modules\Centers\Models\CenterSession;
use App\Modules\Centers\Services\CenterSessionAttendanceService;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Session-based center attendance (separate teacher page). Check students (center
 * OR online) in for a center session, which opens all the session's lessons online
 * for the lesson availability window; list the active grants; and show, per
 * session, who attended. Year-scoped (X-Academic-Year).
 */
class SessionAttendanceController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CenterSessionAttendanceService $service,
    ) {}

    /** Currently-active session grants (access not yet expired), newest first. */
    public function active(): AnonymousResourceCollection
    {
        $records = AttendanceRecord::query()
            ->whereNotNull('center_session_id')
            // Strictly-future (matches the resource's isFuture()); a grant whose
            // window ends exactly now — e.g. one just revoked — is not active.
            ->where(fn ($q) => $q->whereNull('access_expires_at')->orWhere('access_expires_at', '>', now()))
            ->with(['student:id,uuid,name,phone', 'centerSession:id,name,session_at'])
            ->latest('id')
            ->paginate(50);

        return SessionAttendanceResource::collection($records);
    }

    /** Roster: each session with the students who checked in for it. */
    public function roster(): JsonResponse
    {
        $records = AttendanceRecord::query()
            ->whereNotNull('center_session_id')
            ->with(['student:id,uuid,name,phone', 'centerSession:id,name,session_at'])
            ->latest('attended_on')
            ->get();

        $groups = $records->groupBy('center_session_id')->map(function ($rows) {
            $session = $rows->first()->centerSession;

            return [
                'session' => [
                    'id' => $session?->id,
                    'name' => $session?->name,
                    'session_at' => $session?->session_at?->toIso8601String(),
                ],
                'attendees' => $rows->map(fn (AttendanceRecord $r): array => [
                    'id' => $r->id,
                    'student' => ['uuid' => $r->student?->uuid, 'name' => $r->student?->name, 'phone' => $r->student?->phone],
                    'attended_on' => $r->attended_on?->toDateString(),
                    'attended_at' => $r->created_at?->toIso8601String(),
                    'access_expires_at' => $r->access_expires_at?->toIso8601String(),
                    'active' => $r->access_expires_at === null || $r->access_expires_at->isFuture(),
                ])->values(),
            ];
        })->values();

        return response()->json(['data' => $groups]);
    }

    /** Bulk check-in: mark center students present for a session + open its lessons. */
    public function checkin(CheckinAttendanceRequest $request): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $data = $request->validated();
        $markedBy = $request->user()->getKey();

        $center = Center::query()->where('uuid', $data['center_uuid'])->firstOrFail();
        $session = CenterSession::query()->with('lessons')->findOrFail($data['center_session_id']);

        if ($session->center_id !== $center->id) {
            throw new UnprocessableEntityHttpException('This session does not belong to the chosen center.');
        }

        $marked = 0;
        $skipped = [];

        DB::transaction(function () use ($data, $tenantId, $center, $session, $markedBy, &$marked, &$skipped): void {
            foreach ($data['students'] as $uuid) {
                $user = User::query()->where('uuid', $uuid)->first();
                if ($user === null || ! $this->isStudentMember($tenantId, $user)) {
                    $skipped[] = $uuid;

                    continue;
                }

                $this->service->checkin($tenantId, $center, $session, $user, $markedBy);
                $marked++;
            }
        });

        return response()->json(['data' => ['marked' => $marked, 'skipped' => $skipped]]);
    }

    /** Revoke an active grant: lock the session's lesson windows + cancel the grants. */
    public function revoke(AttendanceRecord $record): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->service->revoke($tenantId, $record);

        return response()->json(['data' => ['revoked' => true]]);
    }

    /**
     * A tenant member with the student role. Study mode is NOT gated: both center
     * and online students may be checked in for a session — check-in opens the
     * session's lessons online for either (an online student gets the same
     * time-boxed lesson access a center student does).
     */
    private function isStudentMember(int $tenantId, User $user): bool
    {
        return TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('role', TenantUserRole::Student->value)
            ->exists();
    }
}

<?php

namespace App\Modules\Centers\Http\Controllers\Teacher;

use App\Models\User;
use App\Modules\Centers\Http\Requests\MarkAttendanceRequest;
use App\Modules\Centers\Http\Resources\AttendanceResource;
use App\Modules\Centers\Models\AttendanceRecord;
use App\Modules\Centers\Models\Center;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Notifications\Services\Events\AbsenceNotifier;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * /teacher/centers/{center}/attendance (M12).
 */
class AttendanceController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AbsenceNotifier $absences,
    ) {}

    public function index(Center $center): AnonymousResourceCollection
    {
        $records = $center->attendance()
            ->with('student:id,uuid,name,phone')
            ->latest('attended_on')
            ->paginate(50);

        return AttendanceResource::collection($records);
    }

    /** Bulk-mark a list of students for a day. Unknown/non-member uuids are skipped. */
    public function store(MarkAttendanceRequest $request, Center $center): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $data = $request->validated();
        $date = $data['attended_on'] ?? now()->toDateString();
        $status = $data['status'] ?? 'present';
        $markedBy = $request->user()->getKey();

        $marked = 0;
        $skipped = [];

        foreach ($data['students'] as $uuid) {
            $user = User::query()->where('uuid', $uuid)->first();
            $isMember = $user !== null && TenantUser::query()
                ->where('tenant_id', $tenantId)->where('user_id', $user->id)
                ->where('role', TenantUserRole::Student->value)->exists();

            if (! $isMember) {
                $skipped[] = $uuid;

                continue;
            }

            $record = AttendanceRecord::updateOrCreate(
                // Pin `center_session_id = null` so day-attendance never matches or
                // overwrites a session check-in row (which shares the day+center+user).
                ['center_id' => $center->id, 'user_id' => $user->id, 'attended_on' => $date, 'center_session_id' => null],
                ['status' => $status, 'marked_by' => $markedBy, 'source' => 'online'],
            );
            $marked++;

            // Only an absence is worth a message, and only the FIRST time it is
            // recorded: re-saving the same day's sheet must not re-alert the
            // parent. `wasRecentlyCreated` distinguishes the two, and a
            // present→absent correction is caught by the status change.
            if ($status === 'absent' && ($record->wasRecentlyCreated || $record->wasChanged('status'))) {
                $this->absences->notify(
                    $tenantId,
                    $user,
                    ['date' => (string) $date],
                    $markedBy,
                );
            }
        }

        return response()->json(['data' => ['marked' => $marked, 'skipped' => $skipped]]);
    }
}

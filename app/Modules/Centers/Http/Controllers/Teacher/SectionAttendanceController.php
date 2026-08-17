<?php

namespace App\Modules\Centers\Http\Controllers\Teacher;

use App\Models\User;
use App\Modules\Catalog\Enums\AccessMode;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Centers\Http\Requests\CheckinAttendanceRequest;
use App\Modules\Centers\Http\Resources\SectionAttendanceResource;
use App\Modules\Centers\Models\AttendanceRecord;
use App\Modules\Centers\Models\Center;
use App\Modules\Centers\Services\SectionAttendanceService;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Section-level center attendance (separate teacher page). Check a center student
 * in for a lesson part and open that part's parent lesson online for the lesson's
 * availability window; list the active grants; and show, per center part, who
 * attended. Year-scoped (X-Academic-Year) — parts/lessons/enrollments all live
 * under an academic year.
 */
class SectionAttendanceController
{
    /** Channels a center student may be checked in for. */
    private const CENTER_MODES = [AccessMode::Center->value, AccessMode::Both->value];

    public function __construct(
        private readonly TenantContext $context,
        private readonly SectionAttendanceService $service,
    ) {}

    /** Center/both parts, for the check-in picker (grouped display is FE's job). */
    public function sections(): JsonResponse
    {
        $sections = LessonSection::query()
            ->whereIn('access_mode', self::CENTER_MODES)
            ->with(['lesson:id,title,course_id', 'lesson.course:id,title'])
            ->ordered()
            ->get()
            ->map(fn (LessonSection $s): array => [
                'id' => $s->id,
                'title' => $s->title,
                'access_mode' => $s->access_mode?->value,
                'lesson' => ['id' => $s->lesson?->id, 'title' => $s->lesson?->title],
                'course' => ['title' => $s->lesson?->course?->title],
            ]);

        return response()->json(['data' => $sections]);
    }

    /** Currently-active section grants (access not yet expired), newest first. */
    public function active(): AnonymousResourceCollection
    {
        $records = AttendanceRecord::query()
            ->whereNotNull('lesson_section_id')
            // Strictly-future (matches the resource's isFuture()); a grant whose
            // window ends exactly now — e.g. one just revoked — is not active.
            ->where(fn ($q) => $q->whereNull('access_expires_at')->orWhere('access_expires_at', '>', now()))
            ->with(['student:id,uuid,name,phone', 'lessonSection:id,title,access_mode,lesson_id', 'lessonSection.lesson:id,title'])
            ->latest('id')
            ->paginate(50);

        return SectionAttendanceResource::collection($records);
    }

    /** Roster: each center part with the students who checked in for it. */
    public function lessons(): JsonResponse
    {
        $records = AttendanceRecord::query()
            ->whereNotNull('lesson_section_id')
            ->with(['student:id,uuid,name,phone', 'lessonSection:id,title,access_mode,lesson_id', 'lessonSection.lesson:id,title'])
            ->latest('attended_on')
            ->get();

        $groups = $records->groupBy('lesson_section_id')->map(function ($rows) {
            $section = $rows->first()->lessonSection;

            return [
                'section' => [
                    'id' => $section?->id,
                    'title' => $section?->title,
                    'access_mode' => $section?->access_mode?->value,
                ],
                'lesson' => ['id' => $section?->lesson?->id, 'title' => $section?->lesson?->title],
                'attendees' => $rows->map(fn (AttendanceRecord $r): array => [
                    'id' => $r->id,
                    'student' => ['uuid' => $r->student?->uuid, 'name' => $r->student?->name, 'phone' => $r->student?->phone],
                    'attended_on' => $r->attended_on?->toDateString(),
                    'access_expires_at' => $r->access_expires_at?->toIso8601String(),
                    'active' => $r->access_expires_at === null || $r->access_expires_at->isFuture(),
                ])->values(),
            ];
        })->values();

        return response()->json(['data' => $groups]);
    }

    /** Bulk check-in: mark center students present for one part + open its lesson. */
    public function checkin(CheckinAttendanceRequest $request): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $data = $request->validated();
        $markedBy = $request->user()->getKey();

        $center = Center::query()->where('uuid', $data['center_uuid'])->firstOrFail();
        $section = LessonSection::query()->findOrFail($data['lesson_section_id']);

        if (! in_array($section->access_mode?->value, self::CENTER_MODES, true)) {
            throw new UnprocessableEntityHttpException('This part is not a center part.');
        }

        $marked = 0;
        $skipped = [];

        DB::transaction(function () use ($data, $tenantId, $center, $section, $markedBy, &$marked, &$skipped): void {
            foreach ($data['students'] as $uuid) {
                $user = User::query()->where('uuid', $uuid)->first();
                if ($user === null || ! $this->isCenterStudent($tenantId, $user)) {
                    $skipped[] = $uuid;

                    continue;
                }

                $this->service->checkin($tenantId, $center, $section, $user, $markedBy);
                $marked++;
            }
        });

        return response()->json(['data' => ['marked' => $marked, 'skipped' => $skipped]]);
    }

    /** Revoke an active grant: lock the window + cancel the lesson grant. */
    public function revoke(AttendanceRecord $record): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->service->revoke($tenantId, $record);

        return response()->json(['data' => ['revoked' => true]]);
    }

    /** A tenant member with the student role whose study_mode allows center content. */
    private function isCenterStudent(int $tenantId, User $user): bool
    {
        $isMember = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('role', TenantUserRole::Student->value)
            ->exists();

        if (! $isMember) {
            return false;
        }

        $studyMode = StudentProfile::query()->where('user_id', $user->id)->value('study_mode');

        return in_array($studyMode, self::CENTER_MODES, true);
    }
}

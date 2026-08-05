<?php

namespace App\Modules\Identity\Http\Controllers\Teacher;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentAccessTarget;
use App\Modules\Catalog\Http\Resources\ContentAccessOverrideResource;
use App\Modules\Catalog\Models\ContentAccessOverride;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Catalog\Services\ContentAccessOverrideService;
use App\Modules\Identity\Http\Controllers\Teacher\Concerns\ManagesTenantStudents;
use App\Modules\Identity\Http\Requests\GrantContentOverrideRequest;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Manual access overrides (teacher/assistant): grant a specific student direct
 * access to a locked lesson/section/unit — bypassing unmet Content Dependencies
 * / progression gates — and revoke it. Student-scoped like the rest of the
 * `/teacher/students/{student}` surface; gated by `permission:students`.
 */
class StudentContentOverrideController
{
    use ManagesTenantStudents;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ContentAccessOverrideService $overrides,
    ) {}

    /** Active overrides currently granted to this student. */
    public function index(User $student): AnonymousResourceCollection
    {
        $tenantId = (int) $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);

        $rows = ContentAccessOverride::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $student->getKey())
            ->active()
            ->latest('id')
            ->get();

        return ContentAccessOverrideResource::collection($rows);
    }

    /** Grant (or re-activate) an override for the student on a target. */
    public function store(GrantContentOverrideRequest $request, User $student): JsonResponse
    {
        $tenantId = (int) $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);

        $target = ContentAccessTarget::from($request->validated('target_type'));
        $targetId = (int) $request->validated('target_id');
        $this->assertTargetExists($tenantId, $target, $targetId);

        $override = $this->overrides->grant(
            $tenantId,
            (int) $student->getKey(),
            $target,
            $targetId,
            $request->user()->getKey(),
            $request->validated('note'),
        );

        app(AuditLogger::class)->log('content_access.override_granted', [
            'student_id' => $student->getKey(),
            'target_type' => $target->value,
            'target_id' => $targetId,
        ], $tenantId, 'user', $student->getKey());

        return (new ContentAccessOverrideResource($override))->response()->setStatusCode(201);
    }

    /** Revoke an override (soft — retained for the audit trail). */
    public function destroy(User $student, int $override): Response
    {
        $tenantId = (int) $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);

        $row = ContentAccessOverride::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $student->getKey())
            ->where('id', $override)
            ->first();

        abort_if($row === null, 404, 'Override not found for this student.');

        $this->overrides->revoke($row);

        app(AuditLogger::class)->log('content_access.override_revoked', [
            'student_id' => $student->getKey(),
            'override_id' => $row->id,
        ], $tenantId, 'user', $student->getKey());

        return response()->noContent();
    }

    /** The target must be a lesson/section/unit owned by this tenant. */
    private function assertTargetExists(int $tenantId, ContentAccessTarget $target, int $targetId): void
    {
        $exists = match ($target) {
            ContentAccessTarget::Lesson => Lesson::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->whereKey($targetId)->exists(),
            ContentAccessTarget::Section => LessonSection::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->whereKey($targetId)->exists(),
            // Unit target retired (Unit removed, VD §7): no such target exists.
            ContentAccessTarget::Unit => false,
        };

        if (! $exists) {
            throw ValidationException::withMessages([
                'target_id' => __('That :type does not exist in this academy.', ['type' => $target->value]),
            ]);
        }
    }
}

<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Centers\Models\CenterExamGrade;
use App\Modules\Engagement\Models\LessonProgress;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\ParentLink;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Identity\Services\ParentMagicLinkService;
use App\Modules\Identity\Support\DeviceBinding;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Parent portal (M13). A parent (role:parent) sees only the children linked to
 * them in this academy, and each child's progress + results — read-only.
 *
 * Passwordless access (VD R11): a permanent, static magic link mints a normal
 * parent Sanctum session (`magicLogin`), and a multi-child switcher lets one
 * guardian move between siblings within that session (`switchChild`).
 */
class ParentController
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * GET /parent/magic/{token} — passwordless login. Public: resolves the guardian
     * from a permanent magic-link token in the current tenant, then issues a parent
     * session + the child list. Returns 404 on any failure (no token enumeration).
     */
    public function magicLogin(Request $request, ParentMagicLinkService $links, string $token): JsonResponse
    {
        $tenant = $this->context->tenantOrFail();

        $link = $links->consume($token);

        if ($link === null || $link->parent === null) {
            throw new NotFoundHttpException('Invalid or revoked link.');
        }

        $parent = $link->parent;
        $membership = $parent->membershipFor($tenant);

        // The link only logs in an active guardian of THIS academy.
        if ($membership === null || ! $membership->isActive() || $membership->role !== TenantUserRole::Parent) {
            throw new NotFoundHttpException('Invalid or revoked link.');
        }

        // Honour the teacher's "disable sign-in" switch — same gate as password login.
        $profile = TeacherProfile::query()->first();
        if ($profile !== null && ! $profile->login_enabled) {
            throw new AccessDeniedHttpException(__('You are not allowed to login now.'));
        }

        $children = $this->childrenFor($parent);
        $activeChildId = $children->first()['id'] ?? null; // default the switcher to the first child

        $session = $parent->createToken('parent-magic');
        $session->accessToken->forceFill([
            'active_child_id' => $activeChildId,
            'device_id' => DeviceBinding::hash($request->header('X-Device-Id')),
        ])->save();

        return response()->json([
            'data' => [
                'token' => $session->plainTextToken,
                'user' => (new UserResource($parent))->resolve($request),
                'children' => $children->map($this->present())->values(),
                'active_child' => $children->firstWhere('id', $activeChildId)['uuid'] ?? null,
            ],
        ]);
    }

    public function children(Request $request): JsonResponse
    {
        $activeId = $this->activeChildId($request);
        $children = $this->childrenFor($request->user())
            ->map(fn (array $c) => $this->present()($c) + ['active' => $c['id'] === $activeId]);

        return response()->json(['data' => $children->values()]);
    }

    /**
     * POST /parent/switch {student: uuid} — set the active child of THIS session.
     * Only a child linked to the parent (and still active) may be selected.
     */
    public function switchChild(Request $request): JsonResponse
    {
        $uuid = $request->validate([
            'student' => ['required', 'uuid'],
        ])['student'];

        $child = $this->childrenFor($request->user())->firstWhere('uuid', $uuid);

        if ($child === null) {
            throw new NotFoundHttpException('Child not found.');
        }

        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->forceFill(['active_child_id' => $child['id']])->save();
        }

        return response()->json(['data' => $this->present()($child) + ['active' => true]]);
    }

    public function progress(Request $request, User $student): JsonResponse
    {
        $this->assertMyChild($request, $student);

        $rows = LessonProgress::withoutGlobalScopes()
            ->where('tenant_id', $this->context->tenantOrFail()->getKey())
            ->where('user_id', $student->getKey())
            ->with('lesson:id,title')
            ->latest('updated_at')
            ->get()
            ->map(fn (LessonProgress $p) => [
                'lesson_id' => $p->lesson_id,
                'lesson_title' => $p->lesson?->title,
                'watch_percent' => $p->watch_percent,
                'completed' => $p->completed_at !== null,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function results(Request $request, User $student): JsonResponse
    {
        $this->assertMyChild($request, $student);

        $tenantId = $this->context->tenantOrFail()->getKey();

        $online = ExamAttempt::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $student->getKey())
            ->whereIn('status', ['submitted', 'graded'])
            ->with('exam:id,title')
            ->latest('submitted_at')
            ->get()
            ->map(fn (ExamAttempt $a) => [
                'source' => 'online_exam',
                'exam' => $a->exam?->title,
                'status' => $a->status->value,
                'score' => $a->score,
                'max_score' => $a->max_score,
                'submitted_at' => $a->submitted_at?->toIso8601String(),
            ]);

        // Paper (in-center) exam grades (VD R12) — read across every academic year
        // (BelongsToAcademicYear no-ops without a year context on this route).
        $center = CenterExamGrade::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('student_user_id', $student->getKey())
            ->latest('sat_on')
            ->get()
            ->map(fn (CenterExamGrade $g) => [
                'source' => 'center_exam',
                'exam' => $g->title,
                'status' => 'graded',
                'score' => (float) $g->score,
                'max_score' => (float) $g->total_marks,
                'submitted_at' => $g->sat_on?->toIso8601String(),
            ]);

        return response()->json(['data' => $online->concat($center)->values()]);
    }

    /**
     * The parent's linked children that are still ACTIVE students of this academy.
     * A removed child drops via the FK cascade; a disabled (suspended) child is
     * filtered here — the magic link itself stays valid (VD R11 acceptance).
     *
     * @return \Illuminate\Support\Collection<int, array{id:int, uuid:?string, name:?string, phone:?string, relation:?string}>
     */
    private function childrenFor(User $parent): \Illuminate\Support\Collection
    {
        $tenantId = $this->context->tenantOrFail()->getKey();

        $links = ParentLink::query()
            ->where('parent_user_id', $parent->getKey())
            ->with('student:id,uuid,name,phone')
            ->get();

        $activeStudentIds = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('user_id', $links->pluck('student_user_id'))
            ->where('role', TenantUserRole::Student->value)
            ->where('status', MembershipStatus::Active->value)
            ->pluck('user_id')
            ->all();

        return $links
            ->filter(fn (ParentLink $l) => $l->student !== null && in_array($l->student_user_id, $activeStudentIds, true))
            ->map(fn (ParentLink $l) => [
                'id' => $l->student_user_id,
                'uuid' => $l->student?->uuid,
                'name' => $l->student?->name,
                'phone' => $l->student?->phone,
                'relation' => $l->relation,
                'lessons_completed' => LessonProgress::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)->where('user_id', $l->student_user_id)
                    ->whereNotNull('completed_at')->count(),
            ])
            ->values();
    }

    /** Public shape of one child (drops the internal numeric id). */
    private function present(): callable
    {
        return fn (array $c) => [
            'uuid' => $c['uuid'],
            'name' => $c['name'],
            'phone' => $c['phone'],
            'relation' => $c['relation'],
            'lessons_completed' => $c['lessons_completed'] ?? 0,
        ];
    }

    /** The active child selected on the current Sanctum session, if any. */
    private function activeChildId(Request $request): ?int
    {
        $token = $request->user()->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token->active_child_id : null;
    }

    /** The target must be a child linked to the authenticated parent in this tenant. */
    private function assertMyChild(Request $request, User $student): void
    {
        $linked = ParentLink::query()
            ->where('parent_user_id', $request->user()->getKey())
            ->where('student_user_id', $student->getKey())
            ->exists();

        abort_unless($linked, 404, 'Child not found.');
    }
}

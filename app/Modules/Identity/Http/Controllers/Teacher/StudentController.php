<?php

namespace App\Modules\Identity\Http\Controllers\Teacher;

use App\Models\User;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Models\Order;
use App\Modules\Engagement\Models\LessonProgress;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Http\Controllers\Teacher\Concerns\ManagesTenantStudents;
use App\Modules\Identity\Http\Requests\CreateStudentRequest;
use App\Modules\Identity\Http\Requests\UpdateStudentRequest;
use App\Modules\Identity\Http\Resources\StudentResource;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Teacher's control over their own students (roster + lifecycle). All actions
 * are tenant-scoped and operate on the student's MEMBERSHIP + academy data —
 * never on the global user identity (a person may study at other academies too).
 */
class StudentController
{
    use ManagesTenantStudents;

    public function __construct(
        private readonly TenantContext $context,
        private readonly LedgerService $ledger,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $term = $request->query('q');

        $page = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('role', TenantUserRole::Student->value)
            ->when($request->input('filter.status'), fn ($q, $status) => $q->where('status', $status))
            ->when($term, fn ($q, $t) => $q->whereHas('user', fn ($u) => $u
                ->where('name', 'like', "%{$t}%")
                ->orWhere('phone', 'like', "%{$t}%")
                ->orWhere('email', 'like', "%{$t}%")))
            ->with('user')
            ->orderByDesc('id')
            ->paginate(30);

        $this->attachEnrolledCounts($page->getCollection(), $tenantId);

        return StudentResource::collection($page);
    }

    /** 360° view of one student: identity + membership + summary counts. */
    public function show(User $student): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $membership = $this->membershipOrFail($tenantId, $student);

        $wallet = $this->ledger->walletFor($tenantId, $student->getKey());

        return response()->json(['data' => [
            'uuid' => $student->uuid,
            'name' => $student->name,
            'phone' => $student->phone,
            'email' => $student->email,
            'status' => $membership->status->value,
            'joined_at' => $membership->joined_at?->toIso8601String(),
            'summary' => [
                'enrolled_courses' => Enrollment::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)->where('user_id', $student->getKey())
                    ->where('status', EnrollmentStatus::Active->value)->count(),
                'wallet_balance_minor' => $this->ledger->balance($wallet),
                'orders' => Order::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)->where('user_id', $student->getKey())->count(),
                'lessons_completed' => LessonProgress::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)->where('user_id', $student->getKey())
                    ->whereNotNull('completed_at')->count(),
            ],
        ]]);
    }

    /** Manually add a student to this academy (offline onboarding). */
    public function store(CreateStudentRequest $request): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $data = $request->validated();

        $existing = User::query()->where('phone', $data['phone'])->first();

        if ($existing !== null && TenantUser::query()
            ->where('tenant_id', $tenantId)->where('user_id', $existing->id)->exists()) {
            throw ValidationException::withMessages(['phone' => __('This person is already a member of your academy.')]);
        }

        $temporaryPassword = null;

        $student = DB::transaction(function () use ($existing, $data, $tenantId, &$temporaryPassword): User {
            if ($existing !== null) {
                // Existing global identity from elsewhere — link, don't modify it.
                $user = $existing;
            } else {
                $temporaryPassword = $data['password'] ?? Str::password(10);
                $user = User::create([
                    'name' => $data['name'],
                    'phone' => $data['phone'],
                    'email' => $data['email'] ?? null,
                    'password' => $temporaryPassword,       // hashed by cast
                    'phone_verified_at' => now(),           // teacher vouches
                ]);
                if (isset($data['password'])) {
                    $temporaryPassword = null;              // caller set it; don't echo
                }
            }

            TenantUser::create([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'role' => TenantUserRole::Student->value,
                'status' => MembershipStatus::Active->value,
                'joined_at' => now(),
            ]);

            return $user;
        });

        return response()->json(['data' => array_filter([
            'uuid' => $student->uuid,
            'name' => $student->name,
            'phone' => $student->phone,
            'email' => $student->email,
            'status' => MembershipStatus::Active->value,
            'temporary_password' => $temporaryPassword, // present only if generated
        ], fn ($v) => $v !== null)], 201);
    }

    /** Activate or suspend the student's membership. */
    public function update(UpdateStudentRequest $request, User $student): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $membership = $this->membershipOrFail($tenantId, $student);

        $membership->update(['status' => $request->validated('status')]);

        return response()->json(['data' => ['uuid' => $student->uuid, 'status' => $membership->status->value]]);
    }

    /** Remove the student from this academy: drop membership + cancel access. */
    public function destroy(User $student): Response
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $membership = $this->membershipOrFail($tenantId, $student);

        DB::transaction(function () use ($tenantId, $student, $membership): void {
            Enrollment::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $student->getKey())
                ->where('status', EnrollmentStatus::Active->value)
                ->update(['status' => EnrollmentStatus::Cancelled->value]);

            $membership->delete();
        });

        app(AuditLogger::class)->log('student.removed', [
            'student_id' => $student->getKey(),
        ], $tenantId, 'user', $student->getKey());

        return response()->noContent();
    }

    private function attachEnrolledCounts($memberships, int $tenantId): void
    {
        $userIds = $memberships->pluck('user_id');

        $counts = Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('user_id', $userIds)
            ->where('status', EnrollmentStatus::Active->value)
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $memberships->each(fn ($m) => $m->enrolled_courses = (int) ($counts[$m->user_id] ?? 0));
    }
}

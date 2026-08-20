<?php

namespace App\Modules\Identity\Http\Controllers\Teacher;

use App\Models\User;
use App\Modules\Billing\Services\PlanLimitGuard;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Http\Controllers\Teacher\Concerns\ManagesTenantAssistants;
use App\Modules\Identity\Http\Requests\CreateAssistantRequest;
use App\Modules\Identity\Http\Requests\UpdateAssistantRequest;
use App\Modules\Identity\Http\Resources\AssistantResource;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Services\AcademicYearContext;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Teacher management of their academy's assistants (M18). Assistants are global
 * users with an `assistant` membership carrying a delegated permission set;
 * teachers create/link them, re-scope permissions, suspend, and remove — always
 * on the MEMBERSHIP, never the shared global identity.
 */
class AssistantController
{
    use ManagesTenantAssistants;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AcademicYearContext $years,
        private readonly PlanLimitGuard $limits,
    ) {}

    /** The grantable-permission catalog for the teacher UI (GET /teacher/permissions). */
    public function catalog(): JsonResponse
    {
        return response()->json(['data' => Permission::catalog()]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $term = $request->query('q');
        $yearId = $this->years->id();

        $page = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('role', TenantUserRole::Assistant->value)
            // Year-scoped like lessons/packages: when a year is active, only
            // assistants assigned to it show. Null-year assistants are disallowed.
            ->when($yearId, fn ($q, $y) => $q->whereHas('academicYears', fn ($qq) => $qq->where('academic_years.id', $y)))
            ->when($request->input('filter.status'), fn ($q, $status) => $q->where('status', $status))
            ->when($term, fn ($q, $t) => $q->whereHas('user', fn ($u) => $u
                ->where('name', 'like', "%{$t}%")
                ->orWhere('phone', 'like', "%{$t}%")
                ->orWhere('email', 'like', "%{$t}%")))
            ->with(['user', 'academicYears'])
            ->orderByDesc('id')
            ->paginate(30);

        return AssistantResource::collection($page);
    }

    public function show(User $assistant): AssistantResource
    {
        $tenantId = $this->context->tenantOrFail()->getKey();

        return new AssistantResource($this->assistantOrFail($tenantId, $assistant)->load(['user', 'academicYears']));
    }

    /** Create or link an assistant, granting the delegated permissions. */
    public function store(CreateAssistantRequest $request): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $data = $request->validated();

        // Year assignment is mandatory — an assistant must belong to at least one
        // academic year (defaults to the active year when none is passed).
        $yearIds = $this->resolveYearIds($data['academic_year_ids'] ?? null, $tenantId);

        $existing = User::query()->where('phone', $data['phone'])->first();

        if ($existing !== null && TenantUser::query()
            ->where('tenant_id', $tenantId)->where('user_id', $existing->id)->exists()) {
            throw ValidationException::withMessages(['phone' => __('This person is already a member of your academy.')]);
        }

        // Subscription-package ceiling (FR-M03-02).
        $this->limits->ensure($tenantId, 'max_assistants');

        $permissions = Permission::sanitize($data['permissions'] ?? []);
        $temporaryPassword = null;

        $assistant = DB::transaction(function () use ($existing, $data, $tenantId, $permissions, $yearIds, &$temporaryPassword): User {
            if ($existing !== null) {
                $user = $existing; // link an existing global identity — don't modify it
            } else {
                $temporaryPassword = $data['password'] ?? Str::password(10);
                $user = User::create([
                    'name' => $data['name'],
                    'phone' => $data['phone'],
                    'email' => $data['email'] ?? null,
                    'password' => $temporaryPassword, // hashed by cast
                    'phone_verified_at' => now(),     // teacher vouches
                ]);
                if (isset($data['password'])) {
                    $temporaryPassword = null; // caller set it; don't echo
                }
            }

            $membership = TenantUser::create([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'role' => TenantUserRole::Assistant->value,
                'status' => MembershipStatus::Active->value,
                'permissions' => $permissions,
                'joined_at' => now(),
            ]);
            $membership->academicYears()->sync($yearIds);

            return $user;
        });

        app(AuditLogger::class)->log('assistant.created', [
            'assistant_id' => $assistant->getKey(), 'permissions' => $permissions, 'academic_year_ids' => $yearIds,
        ], $tenantId, 'user', $assistant->getKey());

        return response()->json(['data' => array_filter([
            'uuid' => $assistant->uuid,
            'name' => $assistant->name,
            'phone' => $assistant->phone,
            'email' => $assistant->email,
            'status' => MembershipStatus::Active->value,
            'permissions' => $permissions,
            'temporary_password' => $temporaryPassword, // present only if generated
        ], fn ($v) => $v !== null)], 201);
    }

    /** Re-scope permissions and/or activate/suspend the assistant. */
    public function update(UpdateAssistantRequest $request, User $assistant): AssistantResource
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $membership = $this->assistantOrFail($tenantId, $assistant);
        $data = $request->validated();

        $identity = array_intersect_key($data, array_flip(['name', 'email']));
        if ($identity !== []) {
            $assistant->fill($identity)->save();
        }

        $changes = [];

        if (array_key_exists('permissions', $data)) {
            $membership->permissions = Permission::sanitize($data['permissions']);
            $changes['permissions'] = $membership->permissions;
        }

        if (array_key_exists('status', $data)) {
            $membership->status = $data['status'];
            $changes['status'] = $data['status'];
            if ($data['status'] === MembershipStatus::Suspended->value) {
                $assistant->tokens()->delete(); // a suspended assistant is signed out
            }
        }

        if ($membership->isDirty()) {
            $membership->save();
        }

        if (array_key_exists('academic_year_ids', $data)) {
            $yearIds = $this->resolveYearIds($data['academic_year_ids'], $tenantId);
            $membership->academicYears()->sync($yearIds);
            $changes['academic_year_ids'] = $yearIds;
        }

        if ($changes !== []) {
            app(AuditLogger::class)->log('assistant.updated', [
                'assistant_id' => $assistant->getKey(), ...$changes,
            ], $tenantId, 'user', $assistant->getKey());
        }

        return new AssistantResource($membership->fresh()->load(['user', 'academicYears']));
    }

    /**
     * Resolve the assistant's target years to internal ids. Accepts a list of
     * year UUIDs from the client; falls back to the active (header) year when
     * none is given. Throws 422 if the result is empty — a year-less assistant
     * is disallowed.
     *
     * @param  array<int, string>|null  $uuids
     * @return list<int>
     */
    private function resolveYearIds(?array $uuids, int $tenantId): array
    {
        if (! empty($uuids)) {
            $ids = AcademicYear::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('uuid', $uuids)
                ->pluck('id')
                ->all();
        } else {
            $active = $this->years->id();
            $ids = $active !== null ? [$active] : [];
        }

        if ($ids === []) {
            throw ValidationException::withMessages([
                'academic_year_ids' => __('Select at least one academic year for the assistant.'),
            ]);
        }

        return array_values(array_map('intval', $ids));
    }

    /** Remove the assistant from this academy (drop membership + sign out). */
    public function destroy(User $assistant): Response
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $membership = $this->assistantOrFail($tenantId, $assistant);

        DB::transaction(function () use ($assistant, $membership): void {
            $assistant->tokens()->delete();
            $membership->delete();
        });

        app(AuditLogger::class)->log('assistant.removed', [
            'assistant_id' => $assistant->getKey(),
        ], $tenantId, 'user', $assistant->getKey());

        return response()->noContent();
    }
}

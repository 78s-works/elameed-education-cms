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

        $page = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('role', TenantUserRole::Assistant->value)
            ->when($request->input('filter.status'), fn ($q, $status) => $q->where('status', $status))
            ->when($term, fn ($q, $t) => $q->whereHas('user', fn ($u) => $u
                ->where('name', 'like', "%{$t}%")
                ->orWhere('phone', 'like', "%{$t}%")
                ->orWhere('email', 'like', "%{$t}%")))
            ->with('user')
            ->orderByDesc('id')
            ->paginate(30);

        return AssistantResource::collection($page);
    }

    public function show(User $assistant): AssistantResource
    {
        $tenantId = $this->context->tenantOrFail()->getKey();

        return new AssistantResource($this->assistantOrFail($tenantId, $assistant)->load('user'));
    }

    /** Create or link an assistant, granting the delegated permissions. */
    public function store(CreateAssistantRequest $request): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $data = $request->validated();

        $existing = User::query()->where('phone', $data['phone'])->first();

        if ($existing !== null && TenantUser::query()
            ->where('tenant_id', $tenantId)->where('user_id', $existing->id)->exists()) {
            throw ValidationException::withMessages(['phone' => __('This person is already a member of your academy.')]);
        }

        // Subscription-package ceiling (FR-M03-02).
        $this->limits->ensure($tenantId, 'max_assistants');

        $permissions = Permission::sanitize($data['permissions'] ?? []);
        $temporaryPassword = null;

        $assistant = DB::transaction(function () use ($existing, $data, $tenantId, $permissions, &$temporaryPassword): User {
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

            TenantUser::create([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'role' => TenantUserRole::Assistant->value,
                'status' => MembershipStatus::Active->value,
                'permissions' => $permissions,
                'joined_at' => now(),
            ]);

            return $user;
        });

        app(AuditLogger::class)->log('assistant.created', [
            'assistant_id' => $assistant->getKey(), 'permissions' => $permissions,
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

        if ($changes !== []) {
            app(AuditLogger::class)->log('assistant.updated', [
                'assistant_id' => $assistant->getKey(), ...$changes,
            ], $tenantId, 'user', $assistant->getKey());
        }

        return new AssistantResource($membership->fresh()->load('user'));
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

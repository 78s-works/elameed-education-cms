<?php

namespace App\Modules\Identity\Http\Controllers\Teacher;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Http\Controllers\Teacher\Concerns\ManagesTenantStudents;
use App\Modules\Identity\Http\Requests\LinkParentRequest;
use App\Modules\Identity\Models\ParentLink;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Teacher manages the parents linked to one of their students (M13). Linking a
 * parent provisions a `parent` membership so the guardian can log in and follow
 * their child. Operates on membership + link, never the global identity.
 */
class StudentParentController
{
    use ManagesTenantStudents;

    public function __construct(private readonly TenantContext $context) {}

    public function index(User $student): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);

        $links = ParentLink::query()
            ->where('student_user_id', $student->getKey())
            ->with('parent:id,uuid,name,phone,email')
            ->get()
            ->map(fn (ParentLink $l) => [
                'uuid' => $l->parent?->uuid,
                'name' => $l->parent?->name,
                'phone' => $l->parent?->phone,
                'email' => $l->parent?->email,
                'relation' => $l->relation,
            ]);

        return response()->json(['data' => $links]);
    }

    public function store(LinkParentRequest $request, User $student): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);
        $data = $request->validated();

        $existing = User::query()->where('phone', $data['phone'])->first();

        if ($existing !== null && ParentLink::query()
            ->where('student_user_id', $student->getKey())
            ->where('parent_user_id', $existing->id)->exists()) {
            throw ValidationException::withMessages(['phone' => __('This parent is already linked to the student.')]);
        }

        $parent = DB::transaction(function () use ($existing, $data, $tenantId, $student): User {
            // Link an existing account by phone, or create a new parent with the
            // password the teacher supplied (validation requires it when new).
            $parent = $existing ?? User::create([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'password' => $data['password'],
                'phone_verified_at' => now(),
            ]);

            TenantUser::firstOrCreate(
                ['tenant_id' => $tenantId, 'user_id' => $parent->id, 'role' => TenantUserRole::Parent->value],
                ['status' => MembershipStatus::Active->value, 'joined_at' => now()],
            );

            $link = new ParentLink([
                'parent_user_id' => $parent->id,
                'student_user_id' => $student->getKey(),
                'relation' => $data['relation'] ?? null,
            ]);
            $link->tenant_id = $tenantId;
            $link->save();

            return $parent;
        });

        return response()->json(['data' => array_filter([
            'uuid' => $parent->uuid,
            'name' => $parent->name,
            'phone' => $parent->phone,
            'relation' => $data['relation'] ?? null,
        ], fn ($v) => $v !== null)], 201);
    }

    public function destroy(User $student, User $parent): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);

        ParentLink::query()
            ->where('student_user_id', $student->getKey())
            ->where('parent_user_id', $parent->getKey())
            ->delete();

        return response()->json(['data' => ['unlinked' => true]]);
    }
}

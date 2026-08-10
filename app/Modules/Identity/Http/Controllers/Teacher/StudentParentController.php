<?php

namespace App\Modules\Identity\Http\Controllers\Teacher;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Http\Controllers\Teacher\Concerns\ManagesTenantStudents;
use App\Modules\Identity\Http\Requests\LinkParentRequest;
use App\Modules\Identity\Models\ParentLink;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Identity\Services\ParentMagicLinkService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

    /**
     * Re-issue the password of a parent (ولي الأمر) linked to this student.
     * Mirrors StudentController::resetPassword — omit `password` to auto-generate
     * one, returned as `temporary_password`. Scoped to the (student, parent) link
     * so a teacher can only reset guardians of their own students.
     */
    public function resetPassword(Request $request, User $student, User $parent): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);
        $this->assertLinked($student, $parent);

        $validated = $request->validate([
            'password' => ['nullable', 'string', 'min:8', 'max:72'],
        ]);

        $generated = ! isset($validated['password']);
        $password = $validated['password'] ?? Str::password(10);

        $parent->update(['password' => $password]); // hashed by cast
        $parent->tokens()->delete();                // drop existing sessions

        app(AuditLogger::class)->log('parent.password_reset', [
            'student_id' => $student->getKey(),
            'parent_id' => $parent->getKey(),
        ], $tenantId, 'user', $parent->getKey());

        return response()->json(['data' => array_filter([
            'uuid' => $parent->uuid,
            'temporary_password' => $generated ? $password : null,
        ], fn ($v) => $v !== null)]);
    }

    /**
     * Issue (rotating) a permanent passwordless magic link for a linked guardian
     * (VD R11). The RAW token is returned ONCE, as a relative path to hand over
     * (WhatsApp/SMS); only its hash is stored. Any prior link stops working.
     * Scoped to the (student, parent) link — a teacher can only mint for their own.
     */
    public function magicLink(User $student, User $parent, ParentMagicLinkService $links): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);
        $this->assertLinked($student, $parent);

        $raw = $links->issueFor($parent);

        app(AuditLogger::class)->log('parent.magic_link_issued', [
            'student_id' => $student->getKey(),
            'parent_id' => $parent->getKey(),
        ], $tenantId, 'user', $parent->getKey());

        return response()->json(['data' => [
            'uuid' => $parent->uuid,
            'magic_token' => $raw,
            'magic_path' => "/parent/magic/{$raw}",
        ]], 201);
    }

    /** Revoke every magic link for a linked guardian (VD R11 revocable / VD-D5). */
    public function revokeMagicLink(User $student, User $parent, ParentMagicLinkService $links): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);
        $this->assertLinked($student, $parent);

        $links->revokeFor($parent);

        app(AuditLogger::class)->log('parent.magic_link_revoked', [
            'student_id' => $student->getKey(),
            'parent_id' => $parent->getKey(),
        ], $tenantId, 'user', $parent->getKey());

        return response()->json(['data' => ['revoked' => true]]);
    }

    /** The parent must be linked to this student, else 404. */
    private function assertLinked(User $student, User $parent): void
    {
        $linked = ParentLink::query()
            ->where('student_user_id', $student->getKey())
            ->where('parent_user_id', $parent->getKey())
            ->exists();

        if (! $linked) {
            throw new NotFoundHttpException('Parent is not linked to this student.');
        }
    }
}

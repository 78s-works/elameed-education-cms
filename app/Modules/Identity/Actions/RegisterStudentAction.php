<?php

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Centers\Models\Center;
use App\Modules\Centers\Services\CenterIdCodeRedemptionService;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Student self-registration = a student joining an academy (tenant).
 *
 * Identity is GLOBAL (one user per phone/email); membership is PER-TENANT
 * (tenant_user). A student may therefore belong to several teachers at once. A
 * phone that already exists means a returning student joining ANOTHER academy:
 * the existing identity is reused and a fresh membership is attached
 * (cross-tenant self-join). The existing name/email/password are never touched,
 * and the join is verified by exactly the academy's own registration mode (OTP
 * or auto) — no weaker than a first-time sign-up. Only a duplicate membership in
 * the SAME academy (or an email owned by a different identity) is rejected.
 */
class RegisterStudentAction
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly CenterIdCodeRedemptionService $idCodes,
    ) {}

    public function handle(Tenant $tenant, array $data, string $verificationMode = 'auto'): User
    {
        $phone = $data['phone'];
        $email = $data['email'] ?? null;
        $sendOtp = $verificationMode === 'otp';

        // Match the returning identity by phone (the primary identifier).
        $existing = User::query()->where('phone', $phone)->first();

        // An email supplied that belongs to a DIFFERENT identity is a real conflict.
        if ($email !== null) {
            $emailOwnerId = User::query()->where('email', $email)->value('id');
            if ($emailOwnerId !== null && $emailOwnerId !== $existing?->getKey()) {
                throw ValidationException::withMessages([
                    'email' => __('An account with these details already exists. Please log in.'),
                ]);
            }
        }

        // Already a member of THIS academy → a genuine duplicate; send them to log in.
        if ($existing !== null && $existing->membershipFor($tenant) !== null) {
            throw ValidationException::withMessages([
                'phone' => __('You already have an account in this academy. Please log in.'),
            ]);
        }

        $user = DB::transaction(function () use ($existing, $tenant, $data, $phone, $email, $sendOtp): User {
            // Reuse the returning identity, or mint a new global one. A returning
            // student's name/email/password/verification are intentionally left as-is.
            $user = $existing ?? User::create([
                'name' => $data['name'],
                'phone' => $phone,
                'email' => $email,
                'password' => $data['password'], // hashed by the model cast
                'locale' => $data['locale'] ?? 'ar',
            ]);

            if ($existing === null && ! $sendOtp) {
                $user->forceFill(['phone_verified_at' => now()])->save();
            }

            TenantUser::create([
                'tenant_id' => $tenant->getKey(),
                'user_id' => $user->getKey(),
                'role' => TenantUserRole::Student->value,
                'status' => $sendOtp ? MembershipStatus::Pending->value : MembershipStatus::Active->value,
                'joined_at' => $sendOtp ? null : now(),
            ]);

            // Per-academy registration details from the sign-up form. `study_mode`
            // rides in via fields(); the center uuid is resolved to its FK here
            // (RegisterRequest already checked it belongs to this tenant).
            $profile = new StudentProfile(StudentProfile::fields($data));
            $profile->tenant_id = $tenant->getKey();
            $profile->user_id = $user->getKey();

            if (! empty($data['id_code'])) {
                // Center ID-code path (B21): validate + consume the code under lock,
                // then bind center + grade + study_mode from its encoding. The code
                // is the single source of truth (manual center/study_mode/academic_year
                // were prohibited by RegisterRequest). study_mode = center (on-site);
                // hybrid ("both") is not encoded in the code, so it stays a manual choice.
                $idCode = $this->idCodes->consume($tenant->getKey(), $data['id_code'], $user);
                $profile->center_id = $idCode->center_id;
                $profile->study_mode = 'center';
                $profile->academic_year = $idCode->gradeLabel();
                // Best-effort pin: match the code's grade label to a real academic
                // year by name so the student is scoped like the manual path. Stays
                // null (unpinned) if the teacher has no year of that name.
                $profile->academic_year_id = AcademicYear::query()
                    ->where('name', $idCode->gradeLabel())
                    ->value('id');
            } elseif (! empty($data['center'])) {
                $profile->center_id = Center::query()->where('uuid', $data['center'])->value('id');
            }

            // Manual path: resolve the chosen academic-year uuid to its FK and mirror
            // the year name into the free-text `academic_year` label (display + the
            // student's own profile screen). The uuid was validated in RegisterRequest.
            if (empty($data['id_code']) && ! empty($data['academic_year_uuid'])) {
                $year = AcademicYear::query()->where('uuid', $data['academic_year_uuid'])->first();
                if ($year !== null) {
                    $profile->academic_year_id = $year->getKey();
                    $profile->academic_year = $year->name;
                }
            }

            $profile->save();

            return $user;
        });

        if ($sendOtp) {
            $this->otp->issue($phone, OtpPurpose::Register);
        }

        return $user;
    }
}

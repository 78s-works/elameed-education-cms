<?php

namespace App\Modules\Identity\Actions;

use App\Models\User;
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
 * Phase-1 self-registration = a student joining the current academy (tenant).
 * Teacher self-signup is P1.5 (FR-M01-03); teachers/admins are provisioned by
 * the platform admin. Creates the global user + a PENDING student membership and
 * sends a registration OTP; the membership activates on OTP verification.
 *
 * P1 keeps phone globally unique: if it already exists the caller is told to log
 * in (cross-tenant self-join of an existing identity is deferred).
 */
class RegisterStudentAction
{
    public function __construct(private readonly OtpService $otp) {}

    public function handle(Tenant $tenant, array $data): User
    {
        $phone = $data['phone'];
        $email = $data['email'] ?? null;

        if (User::query()->where('phone', $phone)->exists()
            || ($email !== null && User::query()->where('email', $email)->exists())) {
            throw ValidationException::withMessages([
                'phone' => __('An account with these details already exists. Please log in.'),
            ]);
        }

        $user = DB::transaction(function () use ($tenant, $data, $phone, $email): User {
            $user = User::create([
                'name' => $data['name'],
                'phone' => $phone,
                'email' => $email,
                'password' => $data['password'], // hashed by the model cast
                'locale' => $data['locale'] ?? 'ar',
            ]);

            TenantUser::create([
                'tenant_id' => $tenant->getKey(),
                'user_id' => $user->getKey(),
                'role' => TenantUserRole::Student->value,
                'status' => MembershipStatus::Pending->value,
            ]);

            // Per-academy registration details from the sign-up form.
            $profile = new StudentProfile(StudentProfile::fields($data));
            $profile->tenant_id = $tenant->getKey();
            $profile->user_id = $user->getKey();
            $profile->save();

            return $user;
        });

        $this->otp->issue($phone, OtpPurpose::Register);

        return $user;
    }
}

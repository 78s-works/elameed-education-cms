<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\LoginAction;
use App\Modules\Identity\Actions\RegisterStudentAction;
use App\Modules\Identity\Actions\ResetPasswordAction;
use App\Modules\Identity\Actions\VerifyOtpAction;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Http\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\OtpRequestRequest;
use App\Modules\Identity\Http\Requests\OtpVerifyRequest;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Identity\Support\UserLookup;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AuthController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly OtpService $otp,
    ) {}

    /** POST /auth/register — student self-registration into the current tenant. */
    public function register(RegisterRequest $request, RegisterStudentAction $action): JsonResponse
    {
        $tenant = $this->context->tenant();

        if ($tenant === null) {
            throw ValidationException::withMessages([
                'tenant' => __('Please register from an academy site.'),
            ]);
        }

        // The teacher can close self-registration for their own academy.
        $profile = TeacherProfile::query()->first();

        if ($profile !== null && ! $profile->registration_enabled) {
            throw new AccessDeniedHttpException(__('Registration is currently closed for this academy.'));
        }

        $action->handle($tenant, $request->validated());

        return response()->json([
            'data' => [
                'message' => __('A verification code has been sent.'),
                'identifier' => $request->validated('phone'),
                'requires_otp' => true,
            ],
        ], 202);
    }

    /** POST /auth/otp/request — (re)send a code. Generic response, no enumeration. */
    public function requestOtp(OtpRequestRequest $request): JsonResponse
    {
        $identifier = $request->validated('identifier');
        $purpose = OtpPurpose::from($request->validated('purpose'));

        // For login/reset, only actually send if the identifier maps to a user;
        // for register, the phone is the one being registered.
        if ($purpose === OtpPurpose::Register || UserLookup::find($identifier) !== null) {
            $this->otp->issue($identifier, $purpose);
        }

        return response()->json([
            'data' => ['message' => __('If the details are valid, a verification code has been sent.')],
        ]);
    }

    /** POST /auth/otp/verify — verify register/login code → issue a token. */
    public function verifyOtp(OtpVerifyRequest $request, VerifyOtpAction $action): JsonResponse
    {
        $result = $action->handle(
            $request->validated('identifier'),
            OtpPurpose::from($request->validated('purpose')),
            $request->validated('code'),
            $this->context->tenant(),
        );

        return response()->json([
            'data' => [
                'token' => $result['token'],
                'user' => (new UserResource($result['user']))->resolve($request),
            ],
        ]);
    }

    /** POST /auth/login — password login (+ optional login-OTP). */
    public function login(LoginRequest $request, LoginAction $action): JsonResponse
    {
        $result = $action->handle(
            $request->validated('identifier'),
            $request->validated('password'),
            $this->context->tenant(),
            $request->ip(),
            $request->userAgent(),
        );

        if ($result['otp_required']) {
            return response()->json([
                'data' => [
                    'otp_required' => true,
                    'identifier' => $request->validated('identifier'),
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'token' => $result['token'],
                'user' => (new UserResource($result['user']))->resolve($request),
            ],
        ]);
    }

    /** POST /auth/logout — revoke the current access token. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['data' => ['message' => __('Signed out.')]]);
    }

    /** POST /auth/password/forgot — issue a reset code. Generic response. */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $identifier = $request->validated('identifier');

        if (UserLookup::find($identifier) !== null) {
            $this->otp->issue($identifier, OtpPurpose::Reset);
        }

        return response()->json([
            'data' => ['message' => __('If the account exists, a reset code has been sent.')],
        ]);
    }

    /** POST /auth/password/reset — verify reset code + set new password. */
    public function resetPassword(ResetPasswordRequest $request, ResetPasswordAction $action): JsonResponse
    {
        $action->handle(
            $request->validated('identifier'),
            $request->validated('code'),
            $request->validated('password'),
        );

        return response()->json([
            'data' => ['message' => __('Your password has been reset. Please sign in.')],
        ]);
    }
}

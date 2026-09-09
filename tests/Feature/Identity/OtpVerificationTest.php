<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\OtpCode;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Notifications\Contracts\SmsSender;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RecordingSmsSender;
use Tests\TestCase;

/**
 * EDU-003: OtpService::verify used to return true unconditionally. These cover
 * the real check — correct code, wrong code, expiry, reuse and the attempt cap.
 */
class OtpVerificationTest extends TestCase
{
    use RefreshDatabase;

    private RecordingSmsSender $sms;

    private OtpService $otp;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Tenant::create([
            'slug' => 'demo',
            'name' => 'Demo Academy',
            'status' => TenantStatus::Active,
        ]);

        $this->sms = new RecordingSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
        $this->otp = $this->app->make(OtpService::class);
    }

    private function issue(string $identifier = '01000000100', OtpPurpose $purpose = OtpPurpose::Register): string
    {
        $this->otp->issue($identifier, $purpose);
        $code = $this->sms->lastCode();
        $this->assertNotNull($code);

        return $code;
    }

    public function test_correct_code_verifies_and_is_consumed(): void
    {
        $code = $this->issue();

        $this->assertTrue($this->otp->verify('01000000100', OtpPurpose::Register, $code));
        $this->assertNotNull(OtpCode::query()->latest('id')->first()->consumed_at);
    }

    public function test_wrong_code_is_rejected_and_counts_an_attempt(): void
    {
        $code = $this->issue();
        $wrong = str_pad((string) ((((int) $code) + 1) % 1000000), 6, '0', STR_PAD_LEFT);

        $this->assertFalse($this->otp->verify('01000000100', OtpPurpose::Register, $wrong));
        $this->assertSame(1, OtpCode::query()->latest('id')->first()->attempts);
    }

    public function test_expired_code_is_rejected(): void
    {
        $code = $this->issue();

        $this->travel((int) config('otp.ttl', 600) + 1)->seconds();

        $this->assertFalse($this->otp->verify('01000000100', OtpPurpose::Register, $code));
    }

    public function test_a_consumed_code_cannot_be_reused(): void
    {
        $code = $this->issue();

        $this->assertTrue($this->otp->verify('01000000100', OtpPurpose::Register, $code));
        $this->assertFalse($this->otp->verify('01000000100', OtpPurpose::Register, $code));
    }

    public function test_code_is_burned_after_the_attempt_limit(): void
    {
        $code = $this->issue();
        $max = (int) config('otp.max_attempts', 5);

        for ($i = 0; $i < $max; $i++) {
            $this->assertFalse($this->otp->verify('01000000100', OtpPurpose::Register, '999999'));
        }

        // The right code no longer helps: the next call burns the code.
        $this->assertFalse($this->otp->verify('01000000100', OtpPurpose::Register, $code));
        $this->assertNotNull(OtpCode::query()->latest('id')->first()->consumed_at);
    }

    public function test_a_code_issued_for_another_purpose_does_not_verify(): void
    {
        $code = $this->issue('01000000100', OtpPurpose::Register);

        $this->assertFalse($this->otp->verify('01000000100', OtpPurpose::Reset, $code));
    }

    public function test_a_code_issued_for_another_identifier_does_not_verify(): void
    {
        $code = $this->issue('01000000100');

        $this->assertFalse($this->otp->verify('01000000200', OtpPurpose::Register, $code));
    }

    public function test_password_reset_rejects_a_wrong_code(): void
    {
        $user = User::factory()->create(['phone' => '01000000300', 'password' => 'oldpass123']);
        TenantUser::create([
            'tenant_id' => Tenant::where('slug', 'demo')->firstOrFail()->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value,
        ]);

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->postJson('/api/v1/auth/password/forgot', ['identifier' => '01000000300'])
            ->assertOk();

        $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/auth/password/reset', [
            'identifier' => '01000000300',
            'code' => '000000',
            'password' => 'newpass123',
        ])->assertStatus(422);

        // The password was not changed.
        $this->assertTrue(Hash::check('oldpass123', $user->fresh()->password));
    }
}

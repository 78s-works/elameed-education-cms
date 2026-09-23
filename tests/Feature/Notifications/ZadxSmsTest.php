<?php

namespace Tests\Feature\Notifications;

use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Jobs\SendOtpJob;
use App\Modules\Notifications\Contracts\OtpSender;
use App\Modules\Notifications\Contracts\SmsSender;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Models\NotificationChannelSetting;
use App\Modules\Notifications\Services\Engine\TemplatedSmsNotifier;
use App\Modules\Notifications\Sms\SmsOtpSender;
use App\Modules\Notifications\Sms\Zadx\ZadxClient;
use App\Modules\Notifications\Sms\Zadx\ZadxOtpSender;
use App\Modules\Notifications\Sms\Zadx\ZadxSmsSender;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The ZADX gateway (EDU-OPS-004).
 *
 * What these assert is the shape of someone else's API, which we cannot change
 * and cannot see fail until production: the `+20…` msisdn format, the
 * Idempotency-Key that is a 422 when missing, the `{error:{code,message}}`
 * envelope whose CODE is the only thing that says what to fix, and the split
 * between the screening-exempt OTP endpoint and the screened plain-text one.
 */
class ZadxSmsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        config(['sms.driver' => 'zadx']);
    }

    public function test_an_otp_goes_to_the_otp_endpoint_as_a_code(): void
    {
        $this->fakeZadx();
        $this->storeCredentials();

        $this->asTenant(fn () => app(OtpSender::class)->send('01001234567', '482917', 'ignored wording'));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://smsapi.zadx.net/api/v1/otp/send'
                && $request['to'] === '+201001234567' // ZADX documents +20…, not bare 20…
                && $request['otp'] === '482917'
                && $request['sender_id'] === 'ZADX'
                && $request->header('X-Api-Key')[0] === 'pk_test'
                && $request->header('X-Api-Secret')[0] === 'sk_test'
                && $request->header('Idempotency-Key') !== [];
        });
    }

    public function test_the_academy_wording_is_not_sent_on_the_otp_endpoint(): void
    {
        // ZADX renders its own approved template and ignores inline text. If this
        // ever starts passing a message, the academy's copy is silently being
        // dropped somewhere else instead — which is worth failing over.
        $this->fakeZadx();
        $this->storeCredentials();

        $this->asTenant(fn () => app(OtpSender::class)->send('01001234567', '482917', 'كود التحقق 482917'));

        Http::assertSent(fn ($request) => ! isset($request['message']) && ! isset($request['text']));
    }

    public function test_the_idempotency_key_is_stable_for_the_same_code_and_number(): void
    {
        // The reason this matters: the otp queue retries three times. An unstable
        // key charges the academy once per retry.
        $this->fakeZadx();
        $this->storeCredentials();

        $keys = [];

        $this->asTenant(function () use (&$keys) {
            foreach ([1, 2] as $ignored) {
                app(OtpSender::class)->send('01001234567', '482917');
            }
        });

        Http::recorded(function ($request) use (&$keys) {
            $keys[] = $request->header('Idempotency-Key')[0];
        });

        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
    }

    public function test_a_different_code_gets_a_different_key(): void
    {
        $this->fakeZadx();
        $this->storeCredentials();

        $keys = [];

        $this->asTenant(function () {
            app(OtpSender::class)->send('01001234567', '111111');
            app(OtpSender::class)->send('01001234567', '222222');
        });

        Http::recorded(function ($request) use (&$keys) {
            $keys[] = $request->header('Idempotency-Key')[0];
        });

        $this->assertNotSame($keys[0], $keys[1]);
    }

    public function test_a_code_outside_four_to_six_digits_is_refused_before_any_call(): void
    {
        $this->fakeZadx();
        $this->storeCredentials();

        $this->expectExceptionMessage('4 to 6 digit');

        $this->asTenant(fn () => (new ZadxOtpSender(app(ZadxClient::class)))
            ->send('01001234567', '1234567'));
    }

    public function test_plain_text_goes_to_the_sms_endpoint_with_the_full_body(): void
    {
        $this->fakeZadx();
        $this->storeCredentials();

        $this->asTenant(fn () => app(SmsSender::class)->send('01001234567', 'Ahmed was absent today.'));

        Http::assertSent(fn ($request) => $request->url() === 'https://smsapi.zadx.net/api/v1/sms/send'
            && $request['message'] === 'Ahmed was absent today.'
            && $request['to'] === '+201001234567');
    }

    public function test_an_error_envelope_surfaces_the_code_that_says_what_to_fix(): void
    {
        Http::fake(['smsapi.zadx.net/*' => Http::response([
            'error' => ['code' => 'sender_id_not_allowed', 'message' => 'Use a sender ID assigned to your app.'],
        ], 403)]);

        $this->storeCredentials();

        try {
            $this->asTenant(fn () => app(SmsSender::class)->send('01001234567', 'hi'));
            $this->fail('Expected the gateway rejection to throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sender_id_not_allowed', $e->getMessage());
            $this->assertStringContainsString('403', $e->getMessage());
            $this->assertStringContainsString('assigned to your app', $e->getMessage());
        }
    }

    public function test_a_rejection_inside_a_2xx_still_throws(): void
    {
        Http::fake(['smsapi.zadx.net/*' => Http::response(['id' => 7, 'status' => 'failed'], 202)]);

        $this->storeCredentials();

        $this->expectException(RuntimeException::class);

        $this->asTenant(fn () => app(SmsSender::class)->send('01001234567', 'hi'));
    }

    public function test_pending_verification_does_not_throw(): void
    {
        // Credits are reserved and the message may still arrive. Throwing here
        // would mark the notification failed and invite a resend ZADX explicitly
        // says never to make automatically.
        Http::fake(['smsapi.zadx.net/*' => Http::response([
            'id' => 8, 'status' => 'pending_verification',
        ], 202)]);

        $this->storeCredentials();

        $this->asTenant(fn () => app(SmsSender::class)->send('01001234567', 'hi'));

        Http::assertSentCount(1);
    }

    public function test_a_blank_sender_id_is_omitted_rather_than_sent_empty(): void
    {
        // An unassigned sender id is a hard 403; omitting it uses the app default.
        $this->fakeZadx();
        $this->storeCredentials(['sender_id' => '']);

        $this->asTenant(fn () => app(SmsSender::class)->send('01001234567', 'hi'));

        Http::assertSent(fn ($request) => ! isset($request['sender_id']));
    }

    public function test_an_academy_without_credentials_never_reaches_the_gateway(): void
    {
        Http::fake();

        $this->expectExceptionMessage('SMS is not enabled for this tenant.');

        $this->asTenant(fn () => app(SmsSender::class)->send('01001234567', 'hi'));
    }

    public function test_an_academy_with_a_half_filled_row_never_reaches_the_gateway(): void
    {
        Http::fake();

        $this->storeCredentials(['api_secret' => '']);

        $this->expectExceptionMessage('not fully configured');

        $this->asTenant(fn () => app(SmsSender::class)->send('01001234567', 'hi'));
    }

    public function test_the_driver_binding_follows_the_configured_driver(): void
    {
        config(['sms.driver' => 'zadx']);
        $this->assertInstanceOf(ZadxOtpSender::class, app(OtpSender::class));
        $this->assertInstanceOf(ZadxSmsSender::class, app(SmsSender::class));

        // Every other driver keeps the pre-ZADX behaviour: the rendered wording
        // travels as ordinary text.
        config(['sms.driver' => 'log']);
        $this->assertInstanceOf(SmsOtpSender::class, app(OtpSender::class));

        config(['sms.driver' => 'nonsense']);
        $this->assertInstanceOf(SmsOtpSender::class, app(OtpSender::class));
    }

    public function test_the_otp_job_delivers_through_the_otp_endpoint(): void
    {
        // End to end for the thing EDU-OPS-004 actually ships: the queued job a
        // registration dispatches must reach /otp/send, not /sms/send.
        $this->fakeZadx();
        $this->storeCredentials();

        (new SendOtpJob('01001234567', 'sms', OtpPurpose::Register, '482917', (int) $this->tenant->getKey()))
            ->handle(app(OtpSender::class), app(TemplatedSmsNotifier::class));

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/otp/send')
            && $request['otp'] === '482917');
    }

    /** @param array<string,mixed> $overrides */
    private function storeCredentials(array $overrides = []): void
    {
        $this->asTenant(function () use ($overrides) {
            NotificationChannelSetting::create([
                'channel' => NotificationChannel::Sms->value,
                'is_active' => true,
                'config' => array_merge([
                    'provider' => 'zadx',
                    'api_key' => 'pk_test',
                    'api_secret' => 'sk_test',
                    'sender_id' => 'ZADX',
                ], $overrides),
            ]);
        });
    }

    private function fakeZadx(): void
    {
        Http::fake(['smsapi.zadx.net/*' => Http::response([
            'id' => 4127,
            'status' => 'queued',
            'to' => '+201001234567',
            'sender_id' => 'ZADX',
            'segments' => 1,
            'cost_credits' => 1,
            'remaining_credits' => 49,
        ], 202)]);
    }

    /** Run a closure with the demo tenant resolved (so BelongsToTenant scopes apply). */
    private function asTenant(callable $fn): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        try {
            $fn();
        } finally {
            app(TenantContext::class)->forget();
        }
    }
}

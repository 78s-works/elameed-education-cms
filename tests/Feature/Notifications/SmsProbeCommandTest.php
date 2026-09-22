<?php

namespace Tests\Feature\Notifications;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Models\NotificationChannelSetting;
use App\Modules\Tenancy\Enums\TenantDomainType;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `sms:probe` is the go-live check for EDU-OPS-004, so what it has to prove is
 * not "a send happened" but "the operator can tell WHICH thing failed" — each
 * test below is one verdict the command must put on screen rather than bury.
 */
class SmsProbeCommandTest extends TestCase
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

    public function test_it_sends_plain_text_and_reports_the_cost(): void
    {
        $this->fakeZadx();
        $this->storeCredentials();

        $this->artisan('sms:probe', ['--tenant' => 'demo', '--phone' => '01001234567', '--message' => 'probe'])
            ->expectsOutputToContain('ZadxSmsSender')
            ->expectsOutputToContain('201001234567') // normalized before sending
            ->expectsOutputToContain('Credits before')
            ->expectsOutputToContain('Credits after')
            // The wording that reaches the handset, so a wrong {app_name} is
            // caught here rather than by a student.
            ->expectsOutputToContain('كود التحقق')
            ->expectsOutputToContain('delivered')
            ->assertExitCode(0);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sms/send')
            && $request['message'] === 'probe');
    }

    public function test_the_otp_flag_probes_the_otp_endpoint(): void
    {
        // The two endpoints fail independently — an app can be provisioned for
        // one mode only (403 mode_not_allowed) — so the probe has to be able to
        // aim at either.
        $this->fakeZadx();
        $this->storeCredentials();

        $this->artisan('sms:probe', ['--tenant' => 'demo', '--phone' => '01001234567', '--otp' => true])
            ->expectsOutputToContain('OTP endpoint')
            ->expectsOutputToContain('ZadxOtpSender')
            ->assertExitCode(0);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/otp/send'));
    }

    public function test_a_gateway_rejection_names_its_error_code(): void
    {
        Http::fake([
            'smsapi.zadx.net/api/v1/sms/balance' => Http::response(['remaining_credits' => 50], 200),
            'smsapi.zadx.net/*' => Http::response([
                'error' => ['code' => 'sender_id_not_allowed', 'message' => 'Use a sender ID assigned to your app.'],
            ], 403),
        ]);

        $this->storeCredentials();

        $this->artisan('sms:probe', ['--tenant' => 'demo', '--phone' => '01001234567', '--message' => 'probe'])
            ->expectsOutputToContain('sender_id_not_allowed')
            ->assertExitCode(1);
    }

    public function test_a_quota_failure_is_distinguishable_from_a_sender_failure(): void
    {
        Http::fake([
            'smsapi.zadx.net/api/v1/sms/balance' => Http::response(['remaining_credits' => 0], 200),
            'smsapi.zadx.net/*' => Http::response([
                'error' => ['code' => 'quota_exhausted', 'message' => 'Add another plan or upgrade.'],
            ], 402),
        ]);

        $this->storeCredentials();

        $this->artisan('sms:probe', ['--tenant' => 'demo', '--phone' => '01001234567', '--message' => 'probe'])
            ->expectsOutputToContain('quota_exhausted')
            ->assertExitCode(1);
    }

    public function test_pending_verification_is_called_out_rather_than_reported_as_success(): void
    {
        // Credits reserved, delivery unknown, and ZADX never resends it. An
        // operator who reads "accepted" and re-runs pays twice for nothing.
        Http::fake([
            'smsapi.zadx.net/api/v1/sms/balance' => Http::response(['remaining_credits' => 49], 200),
            // Real shape, captured from the live API: a page wraps its rows in
            // data.items, not data.
            'smsapi.zadx.net/api/v1/messages*' => Http::response([
                'status' => 'success',
                'data' => ['items' => [['id' => 9, 'status' => 'pending_verification', 'cost_credits' => 1]]],
            ], 200),
            'smsapi.zadx.net/*' => Http::response(['id' => 9, 'status' => 'pending_verification'], 202),
        ]);

        $this->storeCredentials();

        $this->artisan('sms:probe', ['--tenant' => 'demo', '--phone' => '01001234567', '--message' => 'probe'])
            ->expectsOutputToContain('pending_verification')
            ->assertExitCode(0);
    }

    public function test_an_academy_that_never_configured_sms_is_reported_as_such(): void
    {
        Http::fake();

        $this->artisan('sms:probe', ['--tenant' => 'demo', '--phone' => '01001234567', '--message' => 'probe'])
            ->expectsOutputToContain('not enabled for this tenant')
            ->assertExitCode(1);
    }

    public function test_the_log_driver_warns_and_sends_nothing(): void
    {
        // The exact production state this task exists to end: everything looks
        // fine, nothing leaves the server.
        config(['sms.driver' => 'log']);
        Http::fake();

        $this->storeCredentials();

        $this->artisan('sms:probe', ['--tenant' => 'demo', '--phone' => '01001234567', '--message' => 'probe'])
            ->expectsOutputToContain('writes to the log and sends nothing')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_an_academy_resolves_by_host_as_well_as_slug(): void
    {
        $this->fakeZadx();

        TenantDomain::create([
            'tenant_id' => $this->tenant->id,
            'host' => 'demo.edu.raqeem-tech.com',
            'type' => TenantDomainType::Subdomain->value,
            'is_primary' => true,
        ]);

        $this->storeCredentials();

        $this->artisan('sms:probe', [
            '--tenant' => 'https://Demo.edu.raqeem-tech.com/', // normalized to the stored host
            '--phone' => '01001234567',
            '--message' => 'probe',
        ])->expectsOutputToContain('slug: demo')->assertExitCode(0);
    }

    public function test_it_refuses_to_guess_a_destination_or_an_academy(): void
    {
        Http::fake();

        $this->artisan('sms:probe', ['--tenant' => 'demo'])
            ->expectsOutputToContain('Pass --phone')
            ->assertExitCode(1);

        $this->artisan('sms:probe', ['--phone' => '01001234567'])
            ->expectsOutputToContain('Pass --tenant')
            ->assertExitCode(1);

        $this->artisan('sms:probe', ['--tenant' => 'nope', '--phone' => '01001234567'])
            ->expectsOutputToContain('No academy matches')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    private function storeCredentials(): void
    {
        $context = app(TenantContext::class);
        $context->setTenant($this->tenant);

        try {
            NotificationChannelSetting::create([
                'channel' => NotificationChannel::Sms->value,
                'is_active' => true,
                'config' => [
                    'provider' => 'zadx', 'api_key' => 'pk_test',
                    'api_secret' => 'sk_test', 'sender_id' => 'ZADX',
                ],
            ]);
        } finally {
            $context->forget();
        }
    }

    private function fakeZadx(): void
    {
        Http::fake([
            'smsapi.zadx.net/api/v1/sms/balance' => Http::response(['remaining_credits' => 49], 200),
            'smsapi.zadx.net/api/v1/messages*' => Http::response([
                'status' => 'success',
                'data' => ['items' => [[
                    'id' => 4127, 'status' => 'delivered', 'delivery_status' => 'delivered',
                    'billing_status' => 'captured', 'cost_credits' => 1, 'error' => null,
                    'body' => 'كود التحقق الخاص بـ Demo هو: 123456',
                ]]],
            ], 200),
            'smsapi.zadx.net/*' => Http::response([
                'id' => 4127, 'status' => 'queued', 'segments' => 1, 'cost_credits' => 1, 'remaining_credits' => 49,
            ], 202),
        ]);
    }
}

<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Models\NotificationChannelSetting;
use App\Modules\Notifications\Sms\ConnekioSmsSender;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class TenantSmsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        $this->h = ['X-Tenant' => 'demo'];
    }

    private function member(TenantUserRole $role): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    public function test_default_is_disabled_and_no_password_leaks(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->h)->getJson('/api/v1/teacher/sms-settings')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.has_password', false)
            ->assertJsonMissingPath('data.password');
    }

    public function test_teacher_stores_own_credentials_and_enables_sms(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->h)->putJson('/api/v1/teacher/sms-settings', [
            'enabled' => true,
            'sender' => 'Tammam',
            'username' => 'we-user',
            'password' => 'we-secret',
            'account_id' => '987654321',
        ])->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.sender', 'Tammam')
            ->assertJsonPath('data.has_password', true)
            ->assertJsonMissingPath('data.password'); // write-only

        $this->assertDatabaseHas('notification_channel_settings', [
            'tenant_id' => $this->tenant->id,
            'channel' => NotificationChannel::Sms->value,
            'is_active' => true,
        ]);

        // Secret is not stored in plaintext (config is encrypted at rest).
        $row = NotificationChannelSetting::withoutGlobalScopes()->firstOrFail();
        $this->assertStringNotContainsString('we-secret', $row->getRawOriginal('config'));
        $this->assertSame('we-secret', $row->config['password']);
    }

    public function test_enabling_without_complete_credentials_is_rejected(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->h)->putJson('/api/v1/teacher/sms-settings', [
            'enabled' => true,
            'sender' => 'Tammam',
            // username / password / account_id missing
        ])->assertStatus(422);
    }

    public function test_password_is_kept_when_omitted_on_a_later_edit(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->h)->putJson('/api/v1/teacher/sms-settings', [
            'enabled' => true, 'sender' => 'Tammam', 'username' => 'we-user',
            'password' => 'we-secret', 'account_id' => '987654321',
        ])->assertOk();

        // Edit the sender only — no password sent — should still be enabled.
        $this->withHeaders($this->h)->putJson('/api/v1/teacher/sms-settings', [
            'enabled' => true, 'sender' => 'Tammam2',
        ])->assertOk()->assertJsonPath('data.has_password', true);

        $row = NotificationChannelSetting::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('we-secret', $row->config['password']);
        $this->assertSame('Tammam2', $row->config['sender']);
    }

    public function test_student_cannot_manage_sms_settings(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Student));

        $this->withHeaders($this->h)->getJson('/api/v1/teacher/sms-settings')
            ->assertStatus(403);
    }

    public function test_driver_sends_via_the_tenant_own_connekio_data(): void
    {
        Http::fake(['weapi.connekio.com/*' => Http::response([
            'message_id' => 1, 'status' => true, 'status_description' => 'Message Received', 'message_parts' => 1,
        ], 200)]);

        $this->asTenant(function () {
            NotificationChannelSetting::create([
                'channel' => NotificationChannel::Sms->value,
                'is_active' => true,
                'config' => [
                    'provider' => 'connekio', 'sender' => 'Tammam', 'username' => 'we-user',
                    'password' => 'we-secret', 'account_id' => '987654321',
                ],
            ]);

            (new ConnekioSmsSender)->send('01001234567', 'Elameed code: 123456');
        });

        Http::assertSent(function ($request) {
            $expectedAuth = 'Basic '.base64_encode('we-user:we-secret:987654321');

            return str_ends_with($request->url(), '/sms/single')
                && $request->header('Authorization')[0] === $expectedAuth
                && $request['msisdn'] === '201001234567' // normalized
                && $request['sender'] === 'Tammam'
                && (int) $request['account_id'] === 987654321;
        });
    }

    public function test_driver_throws_when_tenant_has_no_active_sms(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);

        $this->asTenant(function () {
            (new ConnekioSmsSender)->send('01001234567', 'hi');
        });
    }

    public function test_driver_throws_when_gateway_rejects(): void
    {
        Http::fake(['weapi.connekio.com/*' => Http::response([
            'status' => false, 'status_description' => 'Invalid sender',
        ], 200)]);

        $this->expectException(RuntimeException::class);

        $this->asTenant(function () {
            NotificationChannelSetting::create([
                'channel' => NotificationChannel::Sms->value,
                'is_active' => true,
                'config' => [
                    'sender' => 'Bad', 'username' => 'u', 'password' => 'p', 'account_id' => '1',
                ],
            ]);

            (new ConnekioSmsSender)->send('01001234567', 'hi');
        });
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

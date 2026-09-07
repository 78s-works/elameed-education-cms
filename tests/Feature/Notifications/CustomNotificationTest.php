<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Notifications\Enums\BroadcastStatus;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Jobs\SendBroadcastJob;
use App\Modules\Notifications\Models\NotificationBroadcast;
use App\Modules\Notifications\Models\NotificationChannelSetting;
use App\Modules\Notifications\Models\NotificationMessage;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Models\NotificationType;
use App\Modules\Notifications\Services\Broadcasts\BroadcastService;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\NotificationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GrantsTenantRoles;
use Tests\TestCase;

/**
 * Custom (human-written) notifications: who may send one, who it reaches, what
 * the sender is told it will cost, and the two rules the spec is explicit about
 * — SMS is off until an academy configures it, and a teacher's own message
 * reaches a student who muted notifications.
 */
class CustomNotificationTest extends TestCase
{
    use GrantsTenantRoles, RefreshDatabase;

    private Tenant $tenant;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(NotificationCatalogSeeder::class);
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        $this->h = ['X-Tenant' => 'demo'];
    }

    private function member(TenantUserRole $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    /** Store complete, enabled WE credentials the way a teacher would. */
    private function enableSms(int $pricePerSegmentMinor = 25): void
    {
        $setting = new NotificationChannelSetting([
            'tenant_id' => $this->tenant->id,
            'channel' => NotificationChannel::Sms->value,
            'config' => [
                'provider' => 'connekio',
                'sender' => 'Demo',
                'username' => 'u',
                'password' => 'p',
                'account_id' => '1',
                'price_per_segment_minor' => $pricePerSegmentMinor,
            ],
            'is_active' => true,
        ]);
        $setting->tenant_id = $this->tenant->id;
        $setting->save();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'audience_type' => 'all_students',
            'channels' => ['database'],
            'title_ar' => 'عنوان',
            'body_ar' => 'رسالة للطلاب.',
        ], $overrides);
    }

    public function test_preview_reports_reach_and_leaves_sms_off_until_the_academy_configures_it(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $this->member(TenantUserRole::Student);
        $this->member(TenantUserRole::Student);

        $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/custom-notifications/preview', $this->payload([
                'channels' => ['database', 'sms'],
            ]))
            ->assertOk()
            ->assertJsonPath('data.recipients', 2)
            ->assertJsonPath('data.reach.in_app', 2)
            // No credentials stored → the channel is simply not available, and
            // nothing is attempted on it.
            ->assertJsonPath('data.reach.sms', 0)
            ->assertJsonPath('data.channels.unavailable', ['sms'])
            ->assertJsonPath('data.sms_cost.total_cost_minor', 0);
    }

    public function test_preview_prices_the_sms_blast_before_it_is_sent(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $this->enableSms(25);
        $this->member(TenantUserRole::Student, ['phone' => '01000000001', 'locale' => 'ar']);
        $this->member(TenantUserRole::Student, ['phone' => '01000000002', 'locale' => 'ar']);

        $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/custom-notifications/preview', $this->payload([
                'channels' => ['sms'],
            ]))
            ->assertOk()
            ->assertJsonPath('data.reach.sms', 2)
            // Arabic bills as UCS-2: one segment each, 2 × 25 piastres.
            ->assertJsonPath('data.sms_cost.total_segments', 2)
            ->assertJsonPath('data.sms_cost.total_cost_minor', 50)
            ->assertJsonPath('data.sms_cost.by_language.ar.encoding', 'UCS-2');
    }

    public function test_sending_stores_the_confirmed_estimate_and_queues_delivery(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $this->member(TenantUserRole::Student);

        $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/custom-notifications', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', BroadcastStatus::Scheduled->value)
            ->assertJsonPath('data.estimate.recipients', 1);

        Queue::assertPushed(SendBroadcastJob::class);
    }

    public function test_delivery_reaches_a_student_who_muted_notifications(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $student = $this->member(TenantUserRole::Student);

        // The student opted out of the custom type on the in-app channel.
        $type = NotificationType::query()->where('key', BroadcastService::TYPE_KEY)->firstOrFail();
        NotificationPreference::create([
            'user_id' => $student->id,
            'notification_type_id' => $type->getKey(),
            'channel' => NotificationChannel::Database->value,
            'is_enabled' => false,
        ]);

        $broadcast = app(BroadcastService::class)->create(
            $this->payload(),
            $this->tenant->id,
            $teacher->id,
        );

        $stats = app(BroadcastService::class)->send($broadcast);

        $this->assertSame(1, $stats['totals']['sent']);
        $this->assertDatabaseHas('new_notifications', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $student->id,
            'title' => 'عنوان',
        ]);
    }

    public function test_each_recipient_reads_the_message_in_their_own_language(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $arabic = $this->member(TenantUserRole::Student, ['locale' => 'ar']);
        $english = $this->member(TenantUserRole::Student, ['locale' => 'en']);

        $broadcast = app(BroadcastService::class)->create($this->payload([
            'title_en' => 'Heads up',
            'body_en' => 'A message for the students.',
        ]), $this->tenant->id, $teacher->id);

        app(BroadcastService::class)->send($broadcast);

        $this->assertSame(
            'عنوان',
            NotificationMessage::withoutGlobalScopes()->where('user_id', $arabic->id)->value('title'),
        );
        $this->assertSame(
            'Heads up',
            NotificationMessage::withoutGlobalScopes()->where('user_id', $english->id)->value('title'),
        );
    }

    public function test_a_second_delivery_of_the_same_broadcast_is_refused(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $this->member(TenantUserRole::Student);

        $service = app(BroadcastService::class);
        $broadcast = $service->create($this->payload(), $this->tenant->id, $teacher->id);

        $service->send($broadcast);
        $second = $service->send($broadcast->fresh());

        $this->assertTrue($second['skipped'] ?? false);
        $this->assertSame(
            1,
            NotificationMessage::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count(),
        );
    }

    public function test_an_assistant_cannot_send_until_the_teacher_grants_the_permission(): void
    {
        $assistant = $this->member(TenantUserRole::Assistant);
        Sanctum::actingAs($assistant);

        $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/custom-notifications', $this->payload())
            ->assertForbidden();

        // Authority only ever arrives through a role in the academy (M20).
        $this->grantPermissions($assistant, $this->tenant, [Permission::NotificationsSend->value]);

        Queue::fake();

        $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/custom-notifications', $this->payload())
            ->assertCreated();
    }

    /**
     * An admin message has no academy of its own, but a delivery always belongs
     * to one: each teacher must find it in THEIR academy's inbox, which is where
     * `new_notifications` (tenant-owned, RLS-forced) can hold it at all.
     */
    public function test_a_platform_message_is_delivered_inside_each_teachers_own_academy(): void
    {
        $mine = $this->member(TenantUserRole::Teacher);

        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $theirs = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $other->id,
            'user_id' => $theirs->id,
            'role' => TenantUserRole::Teacher->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        // tenant_id NULL = the platform's own message to every teacher.
        $broadcast = app(BroadcastService::class)->create([
            'audience_type' => 'teachers',
            'channels' => ['database'],
            'title_ar' => 'تحديث المنصة',
            'body_ar' => 'تم تحديث لوحة المعلم.',
        ], null, null);

        $stats = app(BroadcastService::class)->send($broadcast);

        $this->assertSame(2, $stats['totals']['sent']);
        $this->assertDatabaseHas('new_notifications', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $mine->id,
        ]);
        $this->assertDatabaseHas('new_notifications', [
            'tenant_id' => $other->id,
            'user_id' => $theirs->id,
        ]);
        // No delivery row is ever left without an academy.
        $this->assertSame(0, NotificationMessage::withoutGlobalScopes()->whereNull('tenant_id')->count());
    }

    public function test_an_academy_cannot_address_another_academys_students(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);

        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $outsider = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $other->id,
            'user_id' => $outsider->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        $broadcast = app(BroadcastService::class)->create($this->payload([
            'audience_type' => 'students',
            'audience_ids' => [(string) $outsider->id],
        ]), $this->tenant->id, $teacher->id);

        $stats = app(BroadcastService::class)->send($broadcast);

        $this->assertSame(0, $stats['totals']['sent']);
        $this->assertSame(0, NotificationMessage::withoutGlobalScopes()->count());
    }

    public function test_a_scheduled_message_waits_and_can_be_canceled(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $this->member(TenantUserRole::Student);

        $response = $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/custom-notifications', $this->payload([
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ]))
            ->assertCreated();

        // A future send is NOT queued now — the scheduler releases it.
        Queue::assertNothingPushed();

        $uuid = $response->json('data.uuid');

        $this->withHeaders($this->h)
            ->postJson("/api/v1/teacher/custom-notifications/{$uuid}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', BroadcastStatus::Canceled->value);

        // A canceled message is no longer due, so the scheduler skips it.
        $this->assertSame(0, NotificationBroadcast::query()->due()->count());
    }

    public function test_a_past_send_time_is_rejected_rather_than_fired_immediately(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/custom-notifications', $this->payload([
                'scheduled_at' => now()->subHour()->toIso8601String(),
            ]))
            // The API wraps validation failures in its own envelope
            // (error.code / error.details), not Laravel's default `errors` key.
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonStructure(['error' => ['details' => ['scheduled_at']]]);
    }

    public function test_a_message_with_no_complete_copy_is_rejected(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->h)
            ->postJson('/api/v1/teacher/custom-notifications', [
                'audience_type' => 'all_students',
                'channels' => ['database'],
                'title_ar' => 'عنوان بلا نص',
            ])
            ->assertStatus(422);
    }
}

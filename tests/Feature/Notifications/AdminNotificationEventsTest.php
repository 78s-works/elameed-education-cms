<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationTypeStatus;
use App\Modules\Notifications\Models\NotificationEvent;
use App\Modules\Notifications\Models\NotificationFailure;
use App\Modules\Notifications\Models\NotificationLog;
use App\Modules\Notifications\Models\NotificationMessage;
use App\Modules\Notifications\Models\NotificationType;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * /admin/notifications/events is the platform's delivery auditor, and it is
 * only trustworthy if its counters add up: dispatched = delivered + pending +
 * failed. A failure also has to be openable — a bare count of failed OTPs tells
 * an admin that someone could not sign in, but not who, or why.
 */
class AdminNotificationEventsTest extends TestCase
{
    use RefreshDatabase;

    private function event(Tenant $tenant): NotificationEvent
    {
        $type = NotificationType::create([
            'key' => 'auth.otp',
            'module' => 'account',
            'status' => NotificationTypeStatus::Ready,
        ]);

        return NotificationEvent::create([
            'notification_type_id' => $type->getKey(),
            'tenant_id' => $tenant->getKey(),
            'entity_type' => 'App\\Modules\\Catalog\\Models\\Lesson',
            'entity_id' => 10,
            'payload' => ['ref' => 'x'],
        ]);
    }

    private function message(NotificationEvent $event, User $user, ?string $logStatus): NotificationMessage
    {
        $message = new NotificationMessage([
            'notification_event_id' => $event->getKey(),
            'user_id' => $user->getKey(),
            'channel' => NotificationChannel::Database->value,
            'title' => 't',
            'body' => 'b',
            'is_read' => false,
        ]);
        $message->tenant_id = $event->tenant_id;
        $message->save();

        if ($logStatus !== null) {
            NotificationLog::create([
                'notification_id' => $message->getKey(),
                'status' => $logStatus,
                'metadata' => null,
            ]);
        }

        return $message;
    }

    public function test_counters_reconcile_and_name_the_academy(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $tenant = Tenant::create(['slug' => 'ahmed', 'name' => 'Ahmed Academy', 'status' => TenantStatus::Active]);
        $event = $this->event($tenant);

        $this->message($event, User::factory()->create(), 'sent');
        $this->message($event, User::factory()->create(), 'sent');
        $this->message($event, User::factory()->create(), 'queued');
        NotificationFailure::create([
            'notification_event_id' => $event->getKey(),
            'user_id' => User::factory()->create()->getKey(),
            'channel' => NotificationChannel::Sms->value,
            'error_message' => 'Recipient has no phone number.',
        ]);

        $response = $this->getJson('/api/v1/admin/notifications/events')
            ->assertOk()
            ->assertJsonPath('data.0.delivered_count', 2)
            ->assertJsonPath('data.0.pending_count', 1)
            ->assertJsonPath('data.0.failed_count', 1)
            ->assertJsonPath('data.0.dispatched_count', 4)
            // The academy is named, not identified by its row id.
            ->assertJsonPath('data.0.tenant.name', 'Ahmed Academy');

        $row = $response->json('data.0');
        $this->assertSame(
            $row['dispatched_count'],
            $row['delivered_count'] + $row['pending_count'] + $row['failed_count'],
        );
    }

    public function test_failure_detail_lists_recipient_channel_and_reason(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $tenant = Tenant::create(['slug' => 'ahmed', 'name' => 'Ahmed Academy', 'status' => TenantStatus::Active]);
        $event = $this->event($tenant);
        $recipient = User::factory()->create(['name' => 'Mona']);

        NotificationFailure::create([
            'notification_event_id' => $event->getKey(),
            'user_id' => $recipient->getKey(),
            'channel' => NotificationChannel::Sms->value,
            'error_message' => 'Gateway rejected the number.',
        ]);

        $this->getJson("/api/v1/admin/notifications/events/{$event->getKey()}/failures")
            ->assertOk()
            ->assertJsonPath('data.0.recipient.name', 'Mona')
            ->assertJsonPath('data.0.channel', 'sms')
            ->assertJsonPath('data.0.error_message', 'Gateway rejected the number.');
    }

    public function test_failure_detail_is_admin_only(): void
    {
        $tenant = Tenant::create(['slug' => 'ahmed', 'name' => 'Ahmed', 'status' => TenantStatus::Active]);
        $event = $this->event($tenant);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/admin/notifications/events/{$event->getKey()}/failures")->assertStatus(403);
    }
}

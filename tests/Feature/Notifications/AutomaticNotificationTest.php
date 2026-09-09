<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Engagement\Models\Attachment;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\ParentLink;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Notifications\Contracts\SmsSender;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Models\NotificationChannelSetting;
use App\Modules\Notifications\Models\NotificationEvent;
use App\Modules\Notifications\Models\NotificationFailure;
use App\Modules\Notifications\Models\NotificationMessage;
use App\Modules\Notifications\Services\Events\AbsenceNotifier;
use App\Modules\Notifications\Services\Events\LessonAnnouncer;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Wallet\Models\PaymentReceipt;
use App\Modules\Wallet\Services\PaymentReceiptService;
use Database\Seeders\NotificationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The automatic side of the notification system: business events actually reach
 * the engine, the copy comes from the catalog, and the two rules that keep the
 * inbox honest hold — an announcement fires once, and an academy without SMS
 * credentials sends no SMS and collects no failures for it.
 */
class AutomaticNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(NotificationCatalogSeeder::class);
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        app(TenantContext::class)->setTenant($this->tenant);
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

    /** A stored receipt image — `payment_receipts.attachment_id` is required. */
    private function attachmentFor(User $user): Attachment
    {
        $attachment = new Attachment([
            'kind' => Attachment::KIND_IMAGE,
            'storage_key' => 'attachments/receipt-'.uniqid().'.png',
            'mime' => 'image/png',
            'size_bytes' => 2048,
            'uploaded_by' => $user->id,
        ]);
        $attachment->tenant_id = $this->tenant->id;
        $attachment->save();

        return $attachment;
    }

    /** A year every student in these tests belongs to. */
    private function year(string $name = 'Grade 3'): AcademicYear
    {
        return AcademicYear::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'sort_order' => 1,
        ]);
    }

    private function enableSms(): void
    {
        $setting = new NotificationChannelSetting([
            'channel' => NotificationChannel::Sms->value,
            'config' => [
                'provider' => 'connekio',
                'sender' => 'Demo',
                'username' => 'u',
                'password' => 'p',
                'account_id' => '1',
            ],
            'is_active' => true,
        ]);
        $setting->tenant_id = $this->tenant->id;
        $setting->save();
    }

    public function test_approving_a_receipt_tells_the_student_with_catalog_copy(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $reviewer = $this->member(TenantUserRole::Teacher);

        $receipt = new PaymentReceipt([
            'user_id' => $student->id,
            'method' => 'vodafone_cash',
            'amount_minor' => 25000,
            'currency' => 'EGP',
            'attachment_id' => $this->attachmentFor($student)->id,
            'status' => PaymentReceipt::STATUS_PENDING,
        ]);
        $receipt->tenant_id = $this->tenant->id;
        $receipt->save();

        app(PaymentReceiptService::class)->approve($receipt, $reviewer);

        $message = NotificationMessage::withoutGlobalScopes()
            ->where('user_id', $student->id)
            ->firstOrFail();

        $this->assertSame('تم قبول الإيصال', $message->title);
        // `{amount}` is interpolated from minor units into something readable.
        $this->assertStringContainsString('250.00 EGP', $message->body);
    }

    public function test_uploading_a_receipt_alerts_whoever_reviews_receipts(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $teacher = $this->member(TenantUserRole::Teacher);

        app(PaymentReceiptService::class)->submit(
            $this->tenant->id,
            $student->id,
            'instapay',
            10000,
            $this->attachmentFor($student)->id,
        );

        $this->assertDatabaseHas('new_notifications', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $teacher->id,
            'title' => 'إيصال دفع جديد',
        ]);
    }

    public function test_an_absence_reaches_the_student_and_the_linked_parent(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $parent = $this->member(TenantUserRole::Parent);

        ParentLink::create([
            'tenant_id' => $this->tenant->id,
            'parent_user_id' => $parent->id,
            'student_user_id' => $student->id,
            'relation' => 'father',
        ]);

        app(AbsenceNotifier::class)->notify($this->tenant->id, $student, ['date' => '2026-09-06']);

        $this->assertDatabaseHas('new_notifications', ['user_id' => $student->id]);
        $this->assertDatabaseHas('new_notifications', ['user_id' => $parent->id]);
    }

    public function test_a_guardian_with_no_account_is_texted_when_the_academy_has_sms(): void
    {
        $this->enableSms();

        // Capture what the driver would have sent, rather than asserting on a
        // log line the dev driver happens to write. The collector is an object so
        // the binding closure shares it (an array would be captured by value).
        $collector = new class implements SmsSender
        {
            /** @var list<array{to: string, message: string}> */
            public array $sent = [];

            public function send(string $to, string $message): void
            {
                $this->sent[] = ['to' => $to, 'message' => $message];
            }
        };
        $this->app->instance(SmsSender::class, $collector);

        $student = $this->member(TenantUserRole::Student);
        StudentProfile::create([
            'tenant_id' => $this->tenant->id,
            'academic_year_id' => $this->year()->id,
            'user_id' => $student->id,
            'guardian_phone' => '01000000009',
        ]);

        app(AbsenceNotifier::class)->notify($this->tenant->id, $student, ['date' => '2026-09-06']);

        // The student is texted by the engine (the type carries SMS copy) and the
        // guardian number is texted alongside, from the same template.
        $this->assertContains('01000000009', array_column($collector->sent, 'to'));

        $guardian = collect($collector->sent)->firstWhere('to', '01000000009');
        $this->assertStringContainsString('تم تسجيل غياب', $guardian['message']);
    }

    public function test_an_academy_without_sms_credentials_sends_none_and_records_no_failures(): void
    {
        $student = $this->member(TenantUserRole::Student, ['phone' => '01000000001']);
        $reviewer = $this->member(TenantUserRole::Teacher);

        $receipt = new PaymentReceipt([
            'user_id' => $student->id,
            'method' => 'vodafone_cash',
            'amount_minor' => 5000,
            'currency' => 'EGP',
            'attachment_id' => $this->attachmentFor($student)->id,
            'status' => PaymentReceipt::STATUS_PENDING,
        ]);
        $receipt->tenant_id = $this->tenant->id;
        $receipt->save();

        // `payments.receipt.approved` carries BOTH an in-app and an SMS template.
        app(PaymentReceiptService::class)->approve($receipt, $reviewer);

        $this->assertSame(
            0,
            NotificationMessage::withoutGlobalScopes()
                ->where('channel', NotificationChannel::Sms->value)
                ->count(),
        );
        // The point of the gate: no per-recipient failure noise either.
        $this->assertSame(0, NotificationFailure::query()->count());
    }

    public function test_a_lesson_is_announced_once_and_only_when_it_is_actually_available(): void
    {
        $year = $this->year();

        $student = $this->member(TenantUserRole::Student);
        StudentProfile::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $student->id,
            'academic_year_id' => $year->id,
        ]);

        $hidden = new Lesson([
            'academic_year_id' => $year->id,
            'title' => 'Hidden lesson',
            'visibility' => ContentVisibility::Hidden->value,
        ]);
        $hidden->tenant_id = $this->tenant->id;
        $hidden->save();

        $announcer = app(LessonAnnouncer::class);

        $this->assertFalse($announcer->announce($hidden));
        $this->assertSame(0, NotificationEvent::query()->count());

        $hidden->update(['visibility' => ContentVisibility::Visible->value]);

        $this->assertTrue($announcer->announce($hidden));
        // A second call (a later edit, or the scheduler) must not repeat it.
        $this->assertFalse($announcer->announce($hidden));

        // Once per channel: the announcement now goes to the inbox AND to email
        // (every type ships email copy since EDU-012), but neither repeats.
        foreach ([NotificationChannel::Database, NotificationChannel::Email] as $channel) {
            $this->assertSame(
                1,
                NotificationMessage::withoutGlobalScopes()
                    ->where('user_id', $student->id)
                    ->where('channel', $channel->value)
                    ->count(),
                "Expected exactly one {$channel->value} message.",
            );
        }
    }
}

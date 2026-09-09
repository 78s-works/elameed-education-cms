<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Engagement\Models\Attachment;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\TemplateScope;
use App\Modules\Notifications\Mail\NotificationMail;
use App\Modules\Notifications\Models\NotificationMessage;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\Models\NotificationType;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Wallet\Models\PaymentReceipt;
use App\Modules\Wallet\Services\PaymentReceiptService;
use Database\Seeders\NotificationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * EDU-012: the catalog ships Arabic starters on every implemented channel, an
 * academy's edit stays its own, and the placeholders actually render.
 */
class NotificationCatalogTest extends TestCase
{
    use RefreshDatabase;

    /** The channels every catalog type must ship copy for. */
    private const CHANNELS = ['database', 'email', 'sms'];

    /** The audit type behind human-written messages owns no copy — by design. */
    private const WITHOUT_TEMPLATES = 'custom.message';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(NotificationCatalogSeeder::class);
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'أكاديمية التجربة', 'status' => TenantStatus::Active]);
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

    public function test_every_type_ships_arabic_and_english_copy_on_all_three_channels(): void
    {
        $types = NotificationType::query()->where('key', '!=', self::WITHOUT_TEMPLATES)->get();

        $this->assertGreaterThan(20, $types->count(), 'The catalog looks unexpectedly small.');

        foreach ($types as $type) {
            $templates = NotificationTemplate::query()
                ->where('notification_type_id', $type->getKey())
                ->where('scope', TemplateScope::System->value)
                ->with('translations')
                ->get()
                ->keyBy(fn (NotificationTemplate $template) => $template->channel->value);

            foreach (self::CHANNELS as $channel) {
                $template = $templates->get($channel);
                $this->assertNotNull($template, "{$type->key} has no {$channel} template.");
                $this->assertTrue($template->is_active, "{$type->key}/{$channel} is inactive.");

                foreach (['ar', 'en'] as $language) {
                    $translation = $template->translations->firstWhere('language', $language);
                    $this->assertNotNull($translation, "{$type->key}/{$channel} has no {$language} copy.");
                    $this->assertNotSame('', trim((string) $translation->body), "{$type->key}/{$channel}/{$language} has an empty body.");
                }

                // SMS has nowhere to put a title; the other channels need one.
                $arabic = $template->translations->firstWhere('language', 'ar');
                $channel === NotificationChannel::Sms->value
                    ? $this->assertSame('', (string) $arabic->title)
                    : $this->assertNotSame('', trim((string) $arabic->title), "{$type->key}/{$channel} has no Arabic title.");
            }
        }

        // Nothing but `custom.message` may go without copy.
        $this->assertSame(
            0,
            NotificationTemplate::query()->where('notification_type_id', NotificationType::where('key', self::WITHOUT_TEMPLATES)->value('id'))->count(),
        );
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $templates = DB::table('notification_templates')->count();
        $translations = DB::table('notification_template_translations')->count();
        $types = DB::table('notification_types')->count();

        // Three channels per type, two languages each — bar the audit type.
        $this->assertSame(($types - 1) * count(self::CHANNELS), $templates);
        $this->assertSame($templates * 2, $translations);

        $this->seed(NotificationCatalogSeeder::class);

        $this->assertSame($types, DB::table('notification_types')->count());
        $this->assertSame($templates, DB::table('notification_templates')->count());
        $this->assertSame($translations, DB::table('notification_template_translations')->count());
    }

    public function test_the_otp_inbox_copy_never_carries_the_code(): void
    {
        $type = NotificationType::where('key', 'account.otp.requested')->firstOrFail();

        $inApp = NotificationTemplate::query()
            ->where('notification_type_id', $type->getKey())
            ->where('channel', NotificationChannel::Database->value)
            ->with('translations')
            ->firstOrFail();

        foreach ($inApp->translations as $translation) {
            $this->assertStringNotContainsString('{otp}', $translation->body);
        }
    }

    public function test_an_event_renders_its_placeholders_on_every_channel(): void
    {
        Mail::fake();

        $student = $this->member(TenantUserRole::Student, ['email' => 'student@example.test', 'locale' => 'ar']);
        $reviewer = $this->member(TenantUserRole::Teacher);

        $attachment = new Attachment([
            'kind' => Attachment::KIND_IMAGE,
            'storage_key' => 'attachments/receipt-'.uniqid().'.png',
            'mime' => 'image/png',
            'size_bytes' => 2048,
            'uploaded_by' => $student->id,
        ]);
        $attachment->tenant_id = $this->tenant->id;
        $attachment->save();

        $receipt = new PaymentReceipt([
            'user_id' => $student->id,
            'method' => 'vodafone_cash',
            'amount_minor' => 25000,
            'currency' => 'EGP',
            'attachment_id' => $attachment->id,
            'status' => PaymentReceipt::STATUS_PENDING,
        ]);
        $receipt->tenant_id = $this->tenant->id;
        $receipt->save();

        app(PaymentReceiptService::class)->approve($receipt, $reviewer);

        // In-app: the catalog copy, with {amount} rendered.
        $inApp = NotificationMessage::withoutGlobalScopes()
            ->where('user_id', $student->id)
            ->where('channel', NotificationChannel::Database->value)
            ->firstOrFail();
        $this->assertSame('تم قبول الإيصال', $inApp->title);
        $this->assertStringContainsString('250.00 EGP', $inApp->body);

        // Email: same event, Arabic subject, no unrendered placeholder left.
        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) use ($student) {
            return $mail->hasTo($student->email)
                && $mail->subjectLine === 'تم قبول إيصالك'
                && str_contains($mail->bodyText, '250.00 EGP')
                && str_contains($mail->bodyText, 'أكاديمية التجربة')
                && ! str_contains($mail->bodyText, '{amount}');
        });
    }

    public function test_an_academy_edit_is_private_and_reset_restores_the_default(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        Sanctum::actingAs($teacher);

        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $systemBody = $this->systemBody('payments.receipt.approved', NotificationChannel::Email);

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->putJson('/api/v1/teacher/notifications/payments.receipt.approved/channels/email/translations', [
                'language' => 'ar',
                'title' => 'إيصالك اتقبل',
                'body' => 'تمام يا {student.name}، إيصالك اتقبل و{amount} اتضافوا لمحفظتك.',
            ])->assertSuccessful();

        // The platform default is untouched…
        $this->assertSame($systemBody, $this->systemBody('payments.receipt.approved', NotificationChannel::Email));

        // …the copy is this academy's own…
        $override = NotificationTemplate::query()
            ->where('scope', TemplateScope::Tenant->value)
            ->where('tenant_id', $this->tenant->id)
            ->where('channel', NotificationChannel::Email->value)
            ->with('translations')
            ->firstOrFail();
        $this->assertStringContainsString('إيصالك اتقبل', $override->translations->firstWhere('language', 'ar')->body);

        // …and no other academy sees it.
        $this->assertSame(
            0,
            NotificationTemplate::query()->where('scope', TemplateScope::Tenant->value)->where('tenant_id', $other->id)->count(),
        );

        // Reset drops the override; the academy is back on the platform default.
        $this->withHeaders(['X-Tenant' => 'demo'])
            ->deleteJson('/api/v1/teacher/notifications/payments.receipt.approved/channels/email')
            ->assertSuccessful();

        $this->assertSame(
            0,
            NotificationTemplate::query()->where('scope', TemplateScope::Tenant->value)->where('tenant_id', $this->tenant->id)->count(),
        );
        $this->assertSame($systemBody, $this->systemBody('payments.receipt.approved', NotificationChannel::Email));
    }

    private function systemBody(string $key, NotificationChannel $channel, string $language = 'ar'): string
    {
        $template = NotificationTemplate::query()
            ->where('notification_type_id', NotificationType::where('key', $key)->value('id'))
            ->where('channel', $channel->value)
            ->where('scope', TemplateScope::System->value)
            ->with('translations')
            ->firstOrFail();

        return (string) $template->translations->firstWhere('language', $language)->body;
    }
}

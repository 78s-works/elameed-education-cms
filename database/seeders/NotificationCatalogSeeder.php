<?php

namespace Database\Seeders;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationTypeStatus;
use App\Modules\Notifications\Enums\TemplateScope;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\Models\NotificationType;
use Illuminate\Database\Seeder;

/**
 * Seeds the system-scope notification catalog (doc 10 §13): the first real
 * `NotificationType`s plus their `database`/`email`/`sms` system templates and
 * ar/en copy, all flipped to `ready`. GLOBAL reference data — not tenant-scoped;
 * safe to run in production. Idempotent: keyed on the unique type `key` and
 * (template, language).
 *
 * Every type ships with usable Arabic starters on all three implemented channels
 * (ruled 9 Aug, EDU-012), so an academy that turns email or SMS on is never left
 * with a silent channel. An academy edits any of them through the override
 * surface — the first edit takes a private copy and this system row is untouched
 * (TenantNotificationOverrideService). `custom.message` is the one exception: it
 * carries no template because the copy travels with the broadcast.
 *
 * House rules for the copy: Arabic first and plain; SMS is one line, carries the
 * academy name and no title; email may run to a few lines and links the app; any
 * "how to reach us" line uses support@78sworks.io and never a personal mailbox.
 */
class NotificationCatalogSeeder extends Seeder
{
    private const SUPPORT_AR = 'لأي استفسار راسلنا على support@78sworks.io';

    private const SUPPORT_EN = 'Any questions? Write to support@78sworks.io';

    public function run(): void
    {
        foreach ($this->catalog() as $entry) {
            $type = NotificationType::updateOrCreate(
                ['key' => $entry['key']],
                [
                    'module' => $entry['module'],
                    'severity' => $entry['severity'],
                    'is_system' => true,
                    // Everything in the catalog is live unless the entry says
                    // otherwise — the engine refuses to dispatch a non-`ready`
                    // type, so this is how an entry is parked (see
                    // `packages.package.purchased`).
                    'status' => $entry['status'] ?? NotificationTypeStatus::Ready->value,
                ],
            );

            foreach ($entry['channels'] as $channel => $translations) {
                $template = NotificationTemplate::updateOrCreate(
                    [
                        'notification_type_id' => $type->getKey(),
                        'channel' => $channel,
                        'scope' => TemplateScope::System->value,
                        'tenant_id' => null,
                    ],
                    ['is_active' => true],
                );

                foreach ($translations as $language => $copy) {
                    $template->translations()->updateOrCreate(
                        ['language' => $language],
                        ['title' => $copy['title'], 'body' => $copy['body']],
                    );
                }
            }
        }
    }

    /**
     * Titled copy (database + email).
     *
     * @return array<string, array{title: string, body: string}>
     */
    private function copy(string $arTitle, string $arBody, string $enTitle, string $enBody): array
    {
        return [
            'ar' => ['title' => $arTitle, 'body' => $arBody],
            'en' => ['title' => $enTitle, 'body' => $enBody],
        ];
    }

    /**
     * SMS copy — one line, no title (the transport has nowhere to put one).
     *
     * @return array<string, array{title: string, body: string}>
     */
    private function sms(string $ar, string $en): array
    {
        return [
            'ar' => ['title' => '', 'body' => $ar],
            'en' => ['title' => '', 'body' => $en],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function catalog(): array
    {
        $db = NotificationChannel::Database->value;
        $email = NotificationChannel::Email->value;
        $sms = NotificationChannel::Sms->value;

        return [
            [
                'key' => 'lessons.lesson.available', 'module' => 'lessons', 'severity' => 'info',
                'channels' => [
                    $db => $this->copy(
                        'درس جديد متاح',
                        'الدرس "{lesson.title}" أصبح متاحًا الآن في {tenant_name}.',
                        'A lesson is now available',
                        'The lesson "{lesson.title}" is now available on {tenant_name}.',
                    ),
                    $email => $this->copy(
                        'درس جديد في {tenant_name}',
                        "أصبح الدرس \"{lesson.title}\" متاحًا الآن في {tenant_name}.\n\nيمكنك متابعته من حسابك: {app_url}",
                        'A new lesson on {tenant_name}',
                        "The lesson \"{lesson.title}\" is now available on {tenant_name}.\n\nOpen your account to watch it: {app_url}",
                    ),
                    $sms => $this->sms(
                        '{tenant_name}: الدرس "{lesson.title}" متاح الآن.',
                        '{tenant_name}: the lesson "{lesson.title}" is now available.',
                    ),
                ],
            ],
            [
                'key' => 'lessons.extension.requested', 'module' => 'lessons', 'severity' => 'info',
                'channels' => [
                    $db => $this->copy(
                        'طلب تمديد جديد',
                        'طلب الطالب {student.name} تمديد إتاحة الدرس "{lesson.title}".',
                        'New extension request',
                        '{student.name} requested an extension for "{lesson.title}".',
                    ),
                    $email => $this->copy(
                        'طلب تمديد جديد في {tenant_name}',
                        "طلب الطالب {student.name} تمديد إتاحة الدرس \"{lesson.title}\".\n\nيمكنك قبول الطلب أو رفضه من لوحة التحكم: {app_url}",
                        'New extension request on {tenant_name}',
                        "{student.name} requested an extension for \"{lesson.title}\".\n\nApprove or decline it from your dashboard: {app_url}",
                    ),
                    $sms => $this->sms(
                        '{tenant_name}: طلب الطالب {student.name} تمديد إتاحة الدرس "{lesson.title}".',
                        '{tenant_name}: {student.name} requested an extension for "{lesson.title}".',
                    ),
                ],
            ],
            [
                'key' => 'lessons.extension.approved', 'module' => 'lessons', 'severity' => 'info',
                'channels' => [
                    $db => $this->copy(
                        'تم قبول طلب التمديد',
                        'تم تمديد إتاحة الدرس "{lesson.title}" حتى {until|default:"وقت لاحق"}.',
                        'Extension approved',
                        'Your access to "{lesson.title}" is extended until {until|default:"a later date"}.',
                    ),
                    $email => $this->copy(
                        'تم قبول طلب التمديد',
                        "تم تمديد إتاحة الدرس \"{lesson.title}\" حتى {until|default:\"وقت لاحق\"}.\n\nيمكنك متابعة الدرس من حسابك: {app_url}",
                        'Extension approved',
                        "Your access to \"{lesson.title}\" is extended until {until|default:\"a later date\"}.\n\nContinue the lesson from your account: {app_url}",
                    ),
                    $sms => $this->sms(
                        '{tenant_name}: تم تمديد إتاحة الدرس "{lesson.title}" حتى {until|default:"وقت لاحق"}.',
                        '{tenant_name}: your access to "{lesson.title}" runs until {until|default:"a later date"}.',
                    ),
                ],
            ],
            [
                'key' => 'exams.exam.published', 'module' => 'exams', 'severity' => 'info',
                'channels' => [
                    $db => $this->copy(
                        'اختبار جديد',
                        'تم نشر اختبار "{exam.title}". بالتوفيق!',
                        'New exam published',
                        'The exam "{exam.title}" has been published. Good luck!',
                    ),
                    $email => $this->copy(
                        'اختبار جديد: {exam.title}',
                        "تم نشر اختبار \"{exam.title}\" في {tenant_name}.\n\nادخل حسابك لبدء الاختبار: {app_url}\n\nبالتوفيق!",
                        'New exam: {exam.title}',
                        "The exam \"{exam.title}\" has been published on {tenant_name}.\n\nSign in to start it: {app_url}\n\nGood luck!",
                    ),
                    $sms => $this->sms(
                        '{tenant_name}: تم نشر اختبار "{exam.title}". بالتوفيق!',
                        '{tenant_name}: the exam "{exam.title}" is published. Good luck!',
                    ),
                ],
            ],
            [
                'key' => 'exams.attempt.graded', 'module' => 'exams', 'severity' => 'info',
                'channels' => [
                    $db => $this->copy(
                        'تم تصحيح محاولتك',
                        'حصلت على {score} في اختبار "{exam.title}".',
                        'Your attempt was graded',
                        'You scored {score} on "{exam.title}".',
                    ),
                    $email => $this->copy(
                        'نتيجة اختبار "{exam.title}"',
                        "حصلت على {score} في اختبار \"{exam.title}\".\n\nتفاصيل إجاباتك في حسابك: {app_url}",
                        'Your result for "{exam.title}"',
                        "You scored {score} on \"{exam.title}\".\n\nSee your answers in your account: {app_url}",
                    ),
                    $sms => $this->sms(
                        '{tenant_name}: نتيجتك في اختبار "{exam.title}" هي {score}.',
                        '{tenant_name}: you scored {score} on "{exam.title}".',
                    ),
                ],
            ],
            [
                'key' => 'billing.subscription.expiring', 'module' => 'billing', 'severity' => 'warning',
                'channels' => [
                    $db => $this->copy(
                        'اقتراب انتهاء الاشتراك',
                        'ينتهي اشتراك {tenant_name} في {expires_at}. جدّد للاستمرار.',
                        'Subscription expiring soon',
                        'Your {tenant_name} subscription expires on {expires_at}. Renew to stay active.',
                    ),
                    $email => $this->copy(
                        'اقتراب انتهاء اشتراك {tenant_name}',
                        "ينتهي اشتراك {tenant_name} في {expires_at}.\n\nجدّد الاشتراك للاستمرار دون انقطاع: {app_url}\n\n".self::SUPPORT_AR,
                        'Your {tenant_name} subscription expires soon',
                        "The {tenant_name} subscription expires on {expires_at}.\n\nRenew it to stay online without a break: {app_url}\n\n".self::SUPPORT_EN,
                    ),
                    $sms => $this->sms(
                        'ينتهي اشتراك {tenant_name} في {expires_at}. جدّد الآن.',
                        'Your {tenant_name} subscription expires on {expires_at}. Renew now.',
                    ),
                ],
            ],
            [
                'key' => 'billing.subscription.expired', 'module' => 'billing', 'severity' => 'critical',
                'channels' => [
                    $db => $this->copy(
                        'انتهى الاشتراك',
                        'انتهى اشتراك {tenant_name}. جدّد لاستعادة الوصول.',
                        'Subscription expired',
                        'Your {tenant_name} subscription has expired. Renew to restore access.',
                    ),
                    $email => $this->copy(
                        'انتهى اشتراك {tenant_name}',
                        "انتهى اشتراك {tenant_name} وتوقف وصول طلابك إلى المنصة.\n\nجدّد الآن لاستعادة الوصول: {app_url}\n\n".self::SUPPORT_AR,
                        'Your {tenant_name} subscription has expired',
                        "The {tenant_name} subscription has expired and your students can no longer reach the platform.\n\nRenew now to restore access: {app_url}\n\n".self::SUPPORT_EN,
                    ),
                    $sms => $this->sms(
                        'انتهى اشتراك {tenant_name}. جدّد لاستعادة الوصول.',
                        'Your {tenant_name} subscription has expired. Renew to restore access.',
                    ),
                ],
            ],
            [
                'key' => 'billing.plan_limit.reached', 'module' => 'billing', 'severity' => 'warning',
                'channels' => [
                    $db => $this->copy(
                        'بلغت حد الباقة',
                        'بلغت حد {limit} في باقتك الحالية. قم بالترقية للمزيد.',
                        'Plan limit reached',
                        'You reached your {limit} limit on the current plan. Upgrade for more.',
                    ),
                    $email => $this->copy(
                        'بلغت حد الباقة في {tenant_name}',
                        "بلغت حد {limit} في باقتك الحالية في {tenant_name}.\n\nقم بالترقية للمزيد: {app_url}\n\n".self::SUPPORT_AR,
                        'You reached your plan limit on {tenant_name}',
                        "You reached your {limit} limit on the current {tenant_name} plan.\n\nUpgrade for more: {app_url}\n\n".self::SUPPORT_EN,
                    ),
                    $sms => $this->sms(
                        '{tenant_name}: بلغت حد {limit} في باقتك الحالية. قم بالترقية للمزيد.',
                        '{tenant_name}: you reached your {limit} limit. Upgrade for more.',
                    ),
                ],
            ],
            [
                // NOT live: a completed purchase is announced once, as
                // `payments.order.completed`. This per-package variant is kept in
                // the catalog (status `planning`) for the day package activation
                // needs its own message — a code redemption or a manual grant,
                // where no order exists. A `planning` type never dispatches.
                'key' => 'packages.package.purchased', 'module' => 'packages', 'severity' => 'info',
                'status' => NotificationTypeStatus::Planning->value,
                'channels' => [
                    $db => $this->copy(
                        'تم شراء الحزمة',
                        'تم تفعيل حزمة "{package.title}". استمتع بالمحتوى!',
                        'Package purchased',
                        'Your "{package.title}" package is active. Enjoy!',
                    ),
                    $email => $this->copy(
                        'تم تفعيل حزمة "{package.title}"',
                        "تم تفعيل حزمة \"{package.title}\" في {tenant_name}.\n\nمحتوى الحزمة متاح الآن في حسابك: {app_url}",
                        'Your "{package.title}" package is active',
                        "Your \"{package.title}\" package is active on {tenant_name}.\n\nIts content is waiting in your account: {app_url}",
                    ),
                    $sms => $this->sms(
                        '{tenant_name}: تم تفعيل حزمة "{package.title}".',
                        '{tenant_name}: your "{package.title}" package is active.',
                    ),
                ],
            ],
            [
                'key' => 'qa.answer.posted', 'module' => 'qa', 'severity' => 'info',
                'channels' => [
                    $db => $this->copy(
                        'تمت الإجابة على سؤالك',
                        'تمت إضافة إجابة على سؤالك: "{question.title}".',
                        'Your question was answered',
                        'An answer was posted to your question: "{question.title}".',
                    ),
                    $email => $this->copy(
                        'تمت الإجابة على سؤالك',
                        "تمت إضافة إجابة على سؤالك: \"{question.title}\" في {tenant_name}.\n\nاقرأ الإجابة من حسابك: {app_url}",
                        'Your question was answered',
                        "An answer was posted to your question \"{question.title}\" on {tenant_name}.\n\nRead it in your account: {app_url}",
                    ),
                    $sms => $this->sms(
                        '{tenant_name}: تمت الإجابة على سؤالك "{question.title}".',
                        '{tenant_name}: your question "{question.title}" was answered.',
                    ),
                ],
            ],
            [
                // The code itself goes out on SMS and email only — the in-app copy
                // says a code was sent and never carries it.
                'key' => 'account.otp.requested', 'module' => 'account', 'severity' => 'info',
                'channels' => [
                    $db => $this->copy(
                        'تم إرسال رمز تحقق',
                        'أرسلنا رمز تحقق إلى بياناتك المسجلة. الرمز صالح لفترة قصيرة ولا يُشارك مع أحد.',
                        'A verification code was sent',
                        'We sent a verification code to your registered contact. It is short-lived — never share it.',
                    ),
                    $email => $this->copy(
                        'رمز التحقق في {tenant_name}',
                        "رمز التحقق الخاص بك هو: {otp}\n\nالرمز صالح لفترة قصيرة، ولا تشاركه مع أي شخص.\n\nإذا لم تطلب هذا الرمز تجاهل الرسالة أو ".self::SUPPORT_AR,
                        'Your {tenant_name} verification code',
                        "Your verification code is: {otp}\n\nIt is short-lived — never share it with anyone.\n\nIf you did not request it, ignore this message or ".self::SUPPORT_EN,
                    ),
                    $sms => $this->sms(
                        'رمز التحقق الخاص بك في {tenant_name}: {otp}',
                        'Your {tenant_name} verification code is: {otp}',
                    ),
                ],
            ],
            [
                'key' => 'domains.custom_domain.verified', 'module' => 'domains', 'severity' => 'info',
                'channels' => [
                    $db => $this->copy(
                        'تم تفعيل النطاق',
                        'تم التحقق من النطاق {domain} وأصبح فعّالًا.',
                        'Custom domain verified',
                        'Your domain {domain} has been verified and is now live.',
                    ),
                    $email => $this->copy(
                        'تم تفعيل النطاق {domain}',
                        "تم التحقق من النطاق {domain} وأصبح فعّالًا.\n\nيمكنك الآن استقبال طلابك عليه: {app_url}",
                        'Your domain {domain} is live',
                        "The domain {domain} has been verified and is now live.\n\nYour students can reach you on it: {app_url}",
                    ),
                    $sms => $this->sms(
                        '{tenant_name}: تم التحقق من النطاق {domain} وأصبح فعّالًا.',
                        '{tenant_name}: the domain {domain} is verified and live.',
                    ),
                ],
            ],
            [
                // Fired to staff (teacher + assistants with the `support` permission)
                // when a student opens a ticket (B25 / VD Item 11).
                'key' => 'support.ticket.created', 'module' => 'support', 'severity' => 'info',
                'channels' => [
                    $db => $this->copy(
                        'تذكرة دعم جديدة',
                        'فتح الطالب {student.name} تذكرة دعم: "{ticket.subject}".',
                        'New support ticket',
                        '{student.name} opened a support ticket: "{ticket.subject}".',
                    ),
                    $email => $this->copy(
                        'تذكرة دعم جديدة في {tenant_name}',
                        "فتح الطالب {student.name} تذكرة دعم: \"{ticket.subject}\".\n\nيمكنك الرد من لوحة التحكم: {app_url}",
                        'New support ticket on {tenant_name}',
                        "{student.name} opened a support ticket: \"{ticket.subject}\".\n\nReply from your dashboard: {app_url}",
                    ),
                    $sms => $this->sms(
                        '{tenant_name}: تذكرة دعم جديدة من {student.name}: "{ticket.subject}".',
                        '{tenant_name}: new support ticket from {student.name}: "{ticket.subject}".',
                    ),
                ],
            ],
            [
                // Fired to the ticket owner when staff replies (B25 / VD Item 11).
                'key' => 'support.ticket.replied', 'module' => 'support', 'severity' => 'info',
                'channels' => [
                    $db => $this->copy(
                        'رد على تذكرة الدعم',
                        'رد فريق الدعم على تذكرتك: "{ticket.subject}".',
                        'Reply to your ticket',
                        'Support replied to your ticket: "{ticket.subject}".',
                    ),
                    $email => $this->copy(
                        'رد على تذكرتك "{ticket.subject}"',
                        "رد فريق {tenant_name} على تذكرتك: \"{ticket.subject}\".\n\nاقرأ الرد من حسابك: {app_url}",
                        'A reply to your ticket "{ticket.subject}"',
                        "The {tenant_name} team replied to your ticket: \"{ticket.subject}\".\n\nRead it in your account: {app_url}",
                    ),
                    $sms => $this->sms(
                        '{tenant_name}: تم الرد على تذكرتك "{ticket.subject}".',
                        '{tenant_name}: your ticket "{ticket.subject}" has a reply.',
                    ),
                ],
            ],

            // ── Account ──────────────────────────────────────────────────────
            [
                // First thing a student sees after the account is verified.
                'key' => 'account.welcome', 'module' => 'account', 'severity' => 'info',
                'channels' => [
                    $db => $this->copy(
                        'أهلًا بك في {tenant_name}',
                        'مرحبًا {student.name}، تم تفعيل حسابك. ابدأ الآن بتصفح الدروس والباقات.',
                        'Welcome to {tenant_name}',
                        'Hi {student.name}, your account is active. Start exploring the lessons and packages.',
                    ),
                    $email => $this->copy(
                        'أهلًا بك في {tenant_name}',
                        "مرحبًا {student.name}، تم تفعيل حسابك في {tenant_name}.\n\nابدأ بتصفح الدروس والباقات: {app_url}\n\n".self::SUPPORT_AR,
                        'Welcome to {tenant_name}',
                        "Hi {student.name}, your {tenant_name} account is active.\n\nStart exploring the lessons and packages: {app_url}\n\n".self::SUPPORT_EN,
                    ),
                    $sms => $this->sms(
                        '{tenant_name}: تم تفعيل حسابك. ابدأ بتصفح الدروس والباقات.',
                        '{tenant_name}: your account is active. Start exploring the lessons.',
                    ),
                ],
            ],

            // ── Payments ─────────────────────────────────────────────────────
            [
                // Any completed order — wallet, card or code.
                'key' => 'payments.order.completed', 'module' => 'payments', 'severity' => 'info',
                'channels' => [
                    $db => $this->copy(
                        'تم إتمام الطلب',
                        'تم إتمام طلبك بقيمة {amount} وتفعيل ما اشتريته.',
                        'Order completed',
                        'Your order for {amount} is complete and what you bought is now active.',
                    ),
                    $email => $this->copy(
                        'تم إتمام طلبك في {tenant_name}',
                        "تم إتمام طلبك بقيمة {amount} في {tenant_name}، وتم تفعيل ما اشتريته.\n\nالفاتورة ومحتواك في حسابك: {app_url}",
                        'Your {tenant_name} order is complete',
                        "Your order for {amount} on {tenant_name} is complete and what you bought is now active.\n\nThe invoice and your content are in your account: {app_url}",
                    ),
                    $sms => $this->sms(
                        '{tenant_name}: تم إتمام طلبك بقيمة {amount} وتفعيل ما اشتريته.',
                        '{tenant_name}: your order for {amount} is complete.',
                    ),
                ],
            ],
            [
                'key' => 'payments.order.refunded', 'module' => 'payments', 'severity' => 'warning',
                'channels' => [
                    $db => $this->copy(
                        'تم استرداد المبلغ',
                        'تم استرداد {amount} من طلبك. قد يتم سحب ما اشتريته بهذا الطلب.',
                        'Order refunded',
                        '{amount} was refunded from your order. Access bought with it may be revoked.',
                    ),
                    $email => $this->copy(
                        'تم استرداد مبلغ طلبك',
                        "تم استرداد {amount} من طلبك في {tenant_name}. قد يتم سحب ما اشتريته بهذا الطلب.\n\n".self::SUPPORT_AR,
                        'Your order was refunded',
                        "{amount} was refunded from your {tenant_name} order. Access bought with it may be revoked.\n\n".self::SUPPORT_EN,
                    ),
                    $sms => $this->sms(
                        '{tenant_name}: تم استرداد {amount} من طلبك.',
                        '{tenant_name}: {amount} was refunded from your order.',
                    ),
                ],
            ],
            [
                // → teacher/staff who review receipts. Vodafone Cash / InstaPay.
                'key' => 'payments.receipt.uploaded', 'module' => 'payments', 'severity' => 'info',
                'channels' => [
                    $db => $this->copy(
                        'إيصال دفع جديد',
                        'رفع الطالب {student.name} إيصال {method} بقيمة {amount} للمراجعة.',
                        'New payment receipt',
                        '{student.name} uploaded a {method} receipt for {amount} to review.',
                    ),
                    $email => $this->copy(
                        'إيصال دفع جديد للمراجعة',
                        "رفع الطالب {student.name} إيصال {method} بقيمة {amount} في {tenant_name}.\n\nراجع الإيصال من لوحة التحكم: {app_url}",
                        'A payment receipt is waiting for review',
                        "{student.name} uploaded a {method} receipt for {amount} on {tenant_name}.\n\nReview it from your dashboard: {app_url}",
                    ),
                    $sms => $this->sms(
                        '{tenant_name}: إيصال {method} جديد من {student.name} بقيمة {amount} للمراجعة.',
                        '{tenant_name}: a {method} receipt for {amount} from {student.name} needs review.',
                    ),
                ],
            ],
            [
                'key' => 'payments.receipt.approved', 'module' => 'payments', 'severity' => 'info',
                'channels' => [
                    $db => $this->copy(
                        'تم قبول الإيصال',
                        'تم قبول إيصالك وإضافة {amount} إلى محفظتك.',
                        'Receipt approved',
                        'Your receipt was approved and {amount} was added to your wallet.',
                    ),
                    $email => $this->copy(
                        'تم قبول إيصالك',
                        "تم قبول إيصالك في {tenant_name} وإضافة {amount} إلى محفظتك.\n\nرصيدك ومشترياتك في حسابك: {app_url}",
                        'Your receipt was approved',
                        "Your {tenant_name} receipt was approved and {amount} was added to your wallet.\n\nYour balance is in your account: {app_url}",
                    ),
                    $sms => $this->sms(
                        'تم قبول إيصالك في {tenant_name} وإضافة {amount} إلى محفظتك.',
                        'Your {tenant_name} receipt was approved: {amount} added to your wallet.',
                    ),
                ],
            ],
            [
                'key' => 'payments.receipt.rejected', 'module' => 'payments', 'severity' => 'warning',
                'channels' => [
                    $db => $this->copy(
                        'تم رفض الإيصال',
                        'تم رفض إيصالك. السبب: {reason|default:"غير مذكور"}.',
                        'Receipt rejected',
                        'Your receipt was rejected. Reason: {reason|default:"not given"}.',
                    ),
                    $email => $this->copy(
                        'تم رفض إيصالك',
                        "تم رفض إيصالك في {tenant_name}. السبب: {reason|default:\"غير مذكور\"}.\n\nيمكنك رفع إيصال جديد من حسابك: {app_url}\n\n".self::SUPPORT_AR,
                        'Your receipt was rejected',
                        "Your {tenant_name} receipt was rejected. Reason: {reason|default:\"not given\"}.\n\nYou can upload another one from your account: {app_url}\n\n".self::SUPPORT_EN,
                    ),
                    $sms => $this->sms(
                        'تم رفض إيصالك في {tenant_name}. السبب: {reason|default:"غير مذكور"}.',
                        'Your {tenant_name} receipt was rejected. Reason: {reason|default:"not given"}.',
                    ),
                ],
            ],

            // ── Center (on-premise) students ─────────────────────────────────
            [
                // Also texted to the guardian_phone when the academy has SMS on.
                'key' => 'center.attendance.absent', 'module' => 'center', 'severity' => 'warning',
                'channels' => [
                    $db => $this->copy(
                        'تسجيل غياب',
                        'تم تسجيل غياب {student.name} في حصة {session|default:"اليوم"} بتاريخ {date}.',
                        'Marked absent',
                        '{student.name} was marked absent from {session|default:"today\'s session"} on {date}.',
                    ),
                    $email => $this->copy(
                        'تسجيل غياب في {tenant_name}',
                        "تم تسجيل غياب {student.name} في حصة {session|default:\"اليوم\"} بتاريخ {date} في {tenant_name}.\n\n".self::SUPPORT_AR,
                        'An absence was recorded on {tenant_name}',
                        "{student.name} was marked absent from {session|default:\"today's session\"} on {date} at {tenant_name}.\n\n".self::SUPPORT_EN,
                    ),
                    $sms => $this->sms(
                        '{tenant_name}: تم تسجيل غياب {student.name} بتاريخ {date}.',
                        '{tenant_name}: {student.name} was marked absent on {date}.',
                    ),
                ],
            ],
            [
                'key' => 'center.exam_grade.published', 'module' => 'center', 'severity' => 'info',
                'channels' => [
                    $db => $this->copy(
                        'نتيجة امتحان السنتر',
                        'نتيجتك في "{exam.title}": {score} من {total}.',
                        'Center exam result',
                        'Your result for "{exam.title}": {score} out of {total}.',
                    ),
                    $email => $this->copy(
                        'نتيجة امتحان "{exam.title}"',
                        "نتيجة {student.name} في \"{exam.title}\": {score} من {total} في {tenant_name}.\n\nالتفاصيل في الحساب: {app_url}",
                        'Result for "{exam.title}"',
                        "{student.name} scored {score} out of {total} in \"{exam.title}\" at {tenant_name}.\n\nThe details are in the account: {app_url}",
                    ),
                    $sms => $this->sms(
                        '{tenant_name}: نتيجة {student.name} في "{exam.title}" هي {score} من {total}.',
                        '{tenant_name}: {student.name} scored {score}/{total} in "{exam.title}".',
                    ),
                ],
            ],
            [
                'key' => 'center.activation_code.redeemed', 'module' => 'center', 'severity' => 'info',
                'channels' => [
                    $db => $this->copy(
                        'تم تفعيل الكود',
                        'تم تفعيل الكود بنجاح: {target|default:"تمت إضافة الرصيد"}.',
                        'Code redeemed',
                        'Your code was redeemed: {target|default:"credit added"}.',
                    ),
                    $email => $this->copy(
                        'تم تفعيل الكود',
                        "تم تفعيل الكود بنجاح في {tenant_name}: {target|default:\"تمت إضافة الرصيد\"}.\n\nتفاصيل حسابك: {app_url}",
                        'Your code was redeemed',
                        "Your code was redeemed on {tenant_name}: {target|default:\"credit added\"}.\n\nYour account details: {app_url}",
                    ),
                    $sms => $this->sms(
                        '{tenant_name}: تم تفعيل الكود بنجاح.',
                        '{tenant_name}: your code was redeemed.',
                    ),
                ],
            ],

            // ── Exams: time extensions ───────────────────────────────────────
            [
                'key' => 'exams.extension.requested', 'module' => 'exams', 'severity' => 'info',
                'channels' => [
                    $db => $this->copy(
                        'طلب وقت إضافي',
                        'طلب الطالب {student.name} وقتًا إضافيًا في اختبار "{exam.title}".',
                        'Extra-time request',
                        '{student.name} requested extra time on the exam "{exam.title}".',
                    ),
                    $email => $this->copy(
                        'طلب وقت إضافي في {tenant_name}',
                        "طلب الطالب {student.name} وقتًا إضافيًا في اختبار \"{exam.title}\".\n\nيمكنك قبول الطلب أو رفضه من لوحة التحكم: {app_url}",
                        'Extra-time request on {tenant_name}',
                        "{student.name} requested extra time on the exam \"{exam.title}\".\n\nApprove or decline it from your dashboard: {app_url}",
                    ),
                    $sms => $this->sms(
                        '{tenant_name}: طلب الطالب {student.name} وقتًا إضافيًا في اختبار "{exam.title}".',
                        '{tenant_name}: {student.name} requested extra time on "{exam.title}".',
                    ),
                ],
            ],
            [
                'key' => 'exams.extension.approved', 'module' => 'exams', 'severity' => 'info',
                'channels' => [
                    $db => $this->copy(
                        'تمت الموافقة على الوقت الإضافي',
                        'تمت إضافة {minutes} دقيقة إلى اختبار "{exam.title}".',
                        'Extra time approved',
                        '{minutes} extra minutes were added to your "{exam.title}" attempt.',
                    ),
                    $email => $this->copy(
                        'تمت الموافقة على الوقت الإضافي',
                        "تمت إضافة {minutes} دقيقة إلى محاولتك في اختبار \"{exam.title}\".\n\nتابع الاختبار من حسابك: {app_url}",
                        'Extra time approved',
                        "{minutes} extra minutes were added to your \"{exam.title}\" attempt.\n\nContinue from your account: {app_url}",
                    ),
                    $sms => $this->sms(
                        '{tenant_name}: تمت إضافة {minutes} دقيقة إلى اختبارك "{exam.title}".',
                        '{tenant_name}: {minutes} extra minutes were added to your "{exam.title}" attempt.',
                    ),
                ],
            ],

            // ── Custom (human-written) messages ──────────────────────────────
            [
                // The audit type every custom notification is filed under. It has
                // NO templates on purpose: the copy travels with the broadcast
                // (see BroadcastService), and this row exists so a custom send
                // still lands in notification_events with the automatic ones.
                'key' => 'custom.message', 'module' => 'custom', 'severity' => 'info',
                'channels' => [],
            ],
        ];
    }
}

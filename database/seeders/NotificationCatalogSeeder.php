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
 * `NotificationType`s plus their `database`/`sms` system templates and ar/en copy,
 * all flipped to `ready`. GLOBAL reference data — not tenant-scoped; safe to run
 * in production. Idempotent: keyed on the unique type `key` and (template,language).
 */
class NotificationCatalogSeeder extends Seeder
{
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
     * @return array<int, array<string, mixed>>
     */
    private function catalog(): array
    {
        $db = NotificationChannel::Database->value;
        $sms = NotificationChannel::Sms->value;

        return [
            [
                'key' => 'lessons.lesson.available', 'module' => 'lessons', 'severity' => 'info',
                'channels' => [$db => [
                    'ar' => ['title' => 'درس جديد متاح', 'body' => 'الدرس "{lesson.title}" أصبح متاحًا الآن في {tenant_name}.'],
                    'en' => ['title' => 'A lesson is now available', 'body' => 'The lesson "{lesson.title}" is now available on {tenant_name}.'],
                ]],
            ],
            [
                'key' => 'lessons.extension.requested', 'module' => 'lessons', 'severity' => 'info',
                'channels' => [$db => [
                    'ar' => ['title' => 'طلب تمديد جديد', 'body' => 'طلب الطالب {student.name} تمديد إتاحة الدرس "{lesson.title}".'],
                    'en' => ['title' => 'New extension request', 'body' => '{student.name} requested an extension for "{lesson.title}".'],
                ]],
            ],
            [
                'key' => 'lessons.extension.approved', 'module' => 'lessons', 'severity' => 'info',
                'channels' => [$db => [
                    'ar' => ['title' => 'تم قبول طلب التمديد', 'body' => 'تم تمديد إتاحة الدرس "{lesson.title}" حتى {until|default:"وقت لاحق"}.'],
                    'en' => ['title' => 'Extension approved', 'body' => 'Your access to "{lesson.title}" is extended until {until|default:"a later date"}.'],
                ]],
            ],
            [
                'key' => 'exams.exam.published', 'module' => 'exams', 'severity' => 'info',
                'channels' => [$db => [
                    'ar' => ['title' => 'اختبار جديد', 'body' => 'تم نشر اختبار "{exam.title}". بالتوفيق!'],
                    'en' => ['title' => 'New exam published', 'body' => 'The exam "{exam.title}" has been published. Good luck!'],
                ]],
            ],
            [
                'key' => 'exams.attempt.graded', 'module' => 'exams', 'severity' => 'info',
                'channels' => [$db => [
                    'ar' => ['title' => 'تم تصحيح محاولتك', 'body' => 'حصلت على {score} في اختبار "{exam.title}".'],
                    'en' => ['title' => 'Your attempt was graded', 'body' => 'You scored {score} on "{exam.title}".'],
                ]],
            ],
            [
                'key' => 'billing.subscription.expiring', 'module' => 'billing', 'severity' => 'warning',
                'channels' => [
                    $db => [
                        'ar' => ['title' => 'اقتراب انتهاء الاشتراك', 'body' => 'ينتهي اشتراك {tenant_name} في {expires_at}. جدّد للاستمرار.'],
                        'en' => ['title' => 'Subscription expiring soon', 'body' => 'Your {tenant_name} subscription expires on {expires_at}. Renew to stay active.'],
                    ],
                    $sms => [
                        'ar' => ['title' => '', 'body' => 'ينتهي اشتراك {tenant_name} في {expires_at}. جدّد الآن.'],
                        'en' => ['title' => '', 'body' => 'Your {tenant_name} subscription expires on {expires_at}. Renew now.'],
                    ],
                ],
            ],
            [
                'key' => 'billing.subscription.expired', 'module' => 'billing', 'severity' => 'critical',
                'channels' => [
                    $db => [
                        'ar' => ['title' => 'انتهى الاشتراك', 'body' => 'انتهى اشتراك {tenant_name}. جدّد لاستعادة الوصول.'],
                        'en' => ['title' => 'Subscription expired', 'body' => 'Your {tenant_name} subscription has expired. Renew to restore access.'],
                    ],
                    $sms => [
                        'ar' => ['title' => '', 'body' => 'انتهى اشتراك {tenant_name}. جدّد لاستعادة الوصول.'],
                        'en' => ['title' => '', 'body' => 'Your {tenant_name} subscription has expired. Renew to restore access.'],
                    ],
                ],
            ],
            [
                'key' => 'billing.plan_limit.reached', 'module' => 'billing', 'severity' => 'warning',
                'channels' => [$db => [
                    'ar' => ['title' => 'بلغت حد الباقة', 'body' => 'بلغت حد {limit} في باقتك الحالية. قم بالترقية للمزيد.'],
                    'en' => ['title' => 'Plan limit reached', 'body' => 'You reached your {limit} limit on the current plan. Upgrade for more.'],
                ]],
            ],
            [
                // NOT live: a completed purchase is announced once, as
                // `payments.order.completed`. This per-package variant is kept in
                // the catalog (status `planning`) for the day package activation
                // needs its own message — a code redemption or a manual grant,
                // where no order exists. A `planning` type never dispatches.
                'key' => 'packages.package.purchased', 'module' => 'packages', 'severity' => 'info',
                'status' => NotificationTypeStatus::Planning->value,
                'channels' => [$db => [
                    'ar' => ['title' => 'تم شراء الحزمة', 'body' => 'تم تفعيل حزمة "{package.title}". استمتع بالمحتوى!'],
                    'en' => ['title' => 'Package purchased', 'body' => 'Your "{package.title}" package is active. Enjoy!'],
                ]],
            ],
            [
                'key' => 'qa.answer.posted', 'module' => 'qa', 'severity' => 'info',
                'channels' => [$db => [
                    'ar' => ['title' => 'تمت الإجابة على سؤالك', 'body' => 'تمت إضافة إجابة على سؤالك: "{question.title}".'],
                    'en' => ['title' => 'Your question was answered', 'body' => 'An answer was posted to your question: "{question.title}".'],
                ]],
            ],
            [
                'key' => 'account.otp.requested', 'module' => 'account', 'severity' => 'info',
                'channels' => [$sms => [
                    'ar' => ['title' => '', 'body' => 'رمز التحقق الخاص بك في {tenant_name}: {otp}'],
                    'en' => ['title' => '', 'body' => 'Your {tenant_name} verification code is: {otp}'],
                ]],
            ],
            [
                'key' => 'domains.custom_domain.verified', 'module' => 'domains', 'severity' => 'info',
                'channels' => [$db => [
                    'ar' => ['title' => 'تم تفعيل النطاق', 'body' => 'تم التحقق من النطاق {domain} وأصبح فعّالًا.'],
                    'en' => ['title' => 'Custom domain verified', 'body' => 'Your domain {domain} has been verified and is now live.'],
                ]],
            ],
            [
                // Fired to staff (teacher + assistants with the `support` permission)
                // when a student opens a ticket (B25 / VD Item 11).
                'key' => 'support.ticket.created', 'module' => 'support', 'severity' => 'info',
                'channels' => [$db => [
                    'ar' => ['title' => 'تذكرة دعم جديدة', 'body' => 'فتح الطالب {student.name} تذكرة دعم: "{ticket.subject}".'],
                    'en' => ['title' => 'New support ticket', 'body' => '{student.name} opened a support ticket: "{ticket.subject}".'],
                ]],
            ],
            [
                // Fired to the ticket owner when staff replies (B25 / VD Item 11).
                'key' => 'support.ticket.replied', 'module' => 'support', 'severity' => 'info',
                'channels' => [$db => [
                    'ar' => ['title' => 'رد على تذكرة الدعم', 'body' => 'رد فريق الدعم على تذكرتك: "{ticket.subject}".'],
                    'en' => ['title' => 'Reply to your ticket', 'body' => 'Support replied to your ticket: "{ticket.subject}".'],
                ]],
            ],

            // ── Account ──────────────────────────────────────────────────────
            [
                // First thing a student sees after the account is verified.
                'key' => 'account.welcome', 'module' => 'account', 'severity' => 'info',
                'channels' => [$db => [
                    'ar' => ['title' => 'أهلًا بك في {tenant_name}', 'body' => 'مرحبًا {student.name}، تم تفعيل حسابك. ابدأ الآن بتصفح الدروس والباقات.'],
                    'en' => ['title' => 'Welcome to {tenant_name}', 'body' => 'Hi {student.name}, your account is active. Start exploring the lessons and packages.'],
                ]],
            ],

            // ── Payments ─────────────────────────────────────────────────────
            [
                // Any completed order — wallet, card or code.
                'key' => 'payments.order.completed', 'module' => 'payments', 'severity' => 'info',
                'channels' => [$db => [
                    'ar' => ['title' => 'تم إتمام الطلب', 'body' => 'تم إتمام طلبك بقيمة {amount} وتفعيل ما اشتريته.'],
                    'en' => ['title' => 'Order completed', 'body' => 'Your order for {amount} is complete and what you bought is now active.'],
                ]],
            ],
            [
                'key' => 'payments.order.refunded', 'module' => 'payments', 'severity' => 'warning',
                'channels' => [$db => [
                    'ar' => ['title' => 'تم استرداد المبلغ', 'body' => 'تم استرداد {amount} من طلبك. قد يتم سحب ما اشتريته بهذا الطلب.'],
                    'en' => ['title' => 'Order refunded', 'body' => '{amount} was refunded from your order. Access bought with it may be revoked.'],
                ]],
            ],
            [
                // → teacher/staff who review receipts. Vodafone Cash / InstaPay.
                'key' => 'payments.receipt.uploaded', 'module' => 'payments', 'severity' => 'info',
                'channels' => [$db => [
                    'ar' => ['title' => 'إيصال دفع جديد', 'body' => 'رفع الطالب {student.name} إيصال {method} بقيمة {amount} للمراجعة.'],
                    'en' => ['title' => 'New payment receipt', 'body' => '{student.name} uploaded a {method} receipt for {amount} to review.'],
                ]],
            ],
            [
                'key' => 'payments.receipt.approved', 'module' => 'payments', 'severity' => 'info',
                'channels' => [
                    $db => [
                        'ar' => ['title' => 'تم قبول الإيصال', 'body' => 'تم قبول إيصالك وإضافة {amount} إلى محفظتك.'],
                        'en' => ['title' => 'Receipt approved', 'body' => 'Your receipt was approved and {amount} was added to your wallet.'],
                    ],
                    $sms => [
                        'ar' => ['title' => '', 'body' => 'تم قبول إيصالك في {tenant_name} وإضافة {amount} إلى محفظتك.'],
                        'en' => ['title' => '', 'body' => 'Your {tenant_name} receipt was approved: {amount} added to your wallet.'],
                    ],
                ],
            ],
            [
                'key' => 'payments.receipt.rejected', 'module' => 'payments', 'severity' => 'warning',
                'channels' => [
                    $db => [
                        'ar' => ['title' => 'تم رفض الإيصال', 'body' => 'تم رفض إيصالك. السبب: {reason|default:"غير مذكور"}.'],
                        'en' => ['title' => 'Receipt rejected', 'body' => 'Your receipt was rejected. Reason: {reason|default:"not given"}.'],
                    ],
                    $sms => [
                        'ar' => ['title' => '', 'body' => 'تم رفض إيصالك في {tenant_name}. السبب: {reason|default:"غير مذكور"}.'],
                        'en' => ['title' => '', 'body' => 'Your {tenant_name} receipt was rejected. Reason: {reason|default:"not given"}.'],
                    ],
                ],
            ],

            // ── Center (on-premise) students ─────────────────────────────────
            [
                // Also texted to the guardian_phone when the academy has SMS on.
                'key' => 'center.attendance.absent', 'module' => 'center', 'severity' => 'warning',
                'channels' => [
                    $db => [
                        'ar' => ['title' => 'تسجيل غياب', 'body' => 'تم تسجيل غياب {student.name} في حصة {session|default:"اليوم"} بتاريخ {date}.'],
                        'en' => ['title' => 'Marked absent', 'body' => '{student.name} was marked absent from {session|default:"today\'s session"} on {date}.'],
                    ],
                    $sms => [
                        'ar' => ['title' => '', 'body' => '{tenant_name}: تم تسجيل غياب {student.name} بتاريخ {date}.'],
                        'en' => ['title' => '', 'body' => '{tenant_name}: {student.name} was marked absent on {date}.'],
                    ],
                ],
            ],
            [
                'key' => 'center.exam_grade.published', 'module' => 'center', 'severity' => 'info',
                'channels' => [
                    $db => [
                        'ar' => ['title' => 'نتيجة امتحان السنتر', 'body' => 'نتيجتك في "{exam.title}": {score} من {total}.'],
                        'en' => ['title' => 'Center exam result', 'body' => 'Your result for "{exam.title}": {score} out of {total}.'],
                    ],
                    $sms => [
                        'ar' => ['title' => '', 'body' => '{tenant_name}: نتيجة {student.name} في "{exam.title}" هي {score} من {total}.'],
                        'en' => ['title' => '', 'body' => '{tenant_name}: {student.name} scored {score}/{total} in "{exam.title}".'],
                    ],
                ],
            ],
            [
                'key' => 'center.activation_code.redeemed', 'module' => 'center', 'severity' => 'info',
                'channels' => [$db => [
                    'ar' => ['title' => 'تم تفعيل الكود', 'body' => 'تم تفعيل الكود بنجاح: {target|default:"تمت إضافة الرصيد"}.'],
                    'en' => ['title' => 'Code redeemed', 'body' => 'Your code was redeemed: {target|default:"credit added"}.'],
                ]],
            ],

            // ── Exams: time extensions ───────────────────────────────────────
            [
                'key' => 'exams.extension.requested', 'module' => 'exams', 'severity' => 'info',
                'channels' => [$db => [
                    'ar' => ['title' => 'طلب وقت إضافي', 'body' => 'طلب الطالب {student.name} وقتًا إضافيًا في اختبار "{exam.title}".'],
                    'en' => ['title' => 'Extra-time request', 'body' => '{student.name} requested extra time on the exam "{exam.title}".'],
                ]],
            ],
            [
                'key' => 'exams.extension.approved', 'module' => 'exams', 'severity' => 'info',
                'channels' => [$db => [
                    'ar' => ['title' => 'تمت الموافقة على الوقت الإضافي', 'body' => 'تمت إضافة {minutes} دقيقة إلى اختبار "{exam.title}".'],
                    'en' => ['title' => 'Extra time approved', 'body' => '{minutes} extra minutes were added to your "{exam.title}" attempt.'],
                ]],
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

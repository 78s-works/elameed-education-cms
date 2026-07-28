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
                    'status' => NotificationTypeStatus::Ready->value,
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
                'key' => 'packages.package.purchased', 'module' => 'packages', 'severity' => 'info',
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
        ];
    }
}

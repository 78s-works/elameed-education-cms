<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Assessment\Models\Question;
use App\Modules\Billing\Models\SubscriptionPackage;
use App\Modules\Billing\Models\TenantSubscription;
use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\CourseCategory;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Centers\Models\ActivationCode;
use App\Modules\Centers\Models\AttendanceRecord;
use App\Modules\Centers\Models\Center;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Models\Invoice;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Commerce\Models\Payment;
use App\Modules\Engagement\Models\Badge;
use App\Modules\Engagement\Models\Favorite;
use App\Modules\Engagement\Models\LessonProgress;
use App\Modules\Engagement\Models\PointsEntry;
use App\Modules\Engagement\Models\Review;
use App\Modules\Engagement\Models\StudentBadge;
use App\Modules\Identity\Models\LoginAttempt;
use App\Modules\Identity\Models\OtpCode;
use App\Modules\Identity\Models\ParentLink;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaCallbackEvent;
use App\Modules\Media\Models\MediaRendition;
use App\Modules\Media\Models\MediaUploadSession;
use App\Modules\Media\Models\MediaVersion;
use App\Modules\Media\Models\PlaybackSession;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Reporting\Models\AuditLog;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Tenancy\Support\LandingSchema;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The single project seeder — produces a rich, fully-populated demo database.
 *
 * Design goals (per the seeding brief):
 *   • Real, coherent content in every module — no lorem-ipsum, no orphan rows.
 *   • EVERY column of EVERY application table carries a meaningful value. Where a
 *     column is a nullable "state" marker (read_at, consumed_at, revoked_at,
 *     canceled_at, deleted_at, expires_at, completed_at …) the seeder creates
 *     BOTH states across sibling rows, so the column is populated somewhere and
 *     the dataset still reads like real life (some notifications read, some
 *     unread; some codes redeemed, some active; one retired/soft-deleted course).
 *   • Multi-tenant isolation is exercised end to end: two independent academies,
 *     each a tenant with its own teacher, staff, students, parents, branding +
 *     landing, catalogue, media, commerce, assessment, engagement, centres and
 *     an assigned subscription.
 *
 * Re-runnable: it truncates the application tables first (FK checks off), so
 * `php artisan db:seed` always yields the same clean dataset. Framework tables
 * (migrations, cache*, jobs, job_batches, failed_jobs) are left untouched.
 *
 * Every seeded account uses the password "password". Log in with the account's
 * phone; for a tenant account send the `X-Tenant: <slug>` header (dev) or use
 * the `<slug>.<base_domain>` host.
 */
class DatabaseSeeder extends Seeder
{
    /** Platform commission rate applied when splitting a sale (teacher vs platform). */
    private const COMMISSION = 0.15;

    /** Standard mobile user-agent used for sessions / login attempts / playback. */
    private const UA = 'Mozilla/5.0 (Linux; Android 13; SM-A536E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36';

    /** Every application table this seeder owns, ordered so truncation is FK-safe. */
    private const MANAGED_TABLES = [
        'audit_logs', 'media_callback_events', 'login_attempts', 'otp_codes',
        'notifications', 'student_badges', 'badges', 'points_entries', 'favorites',
        'reviews', 'lesson_progress', 'playback_sessions', 'exam_attempts',
        'questions', 'exams', 'attendance_records', 'activation_codes', 'centers',
        'ledger_entries', 'wallets', 'invoices', 'payments', 'order_items', 'orders',
        'enrollments', 'bundle_items', 'bundles', 'media_renditions',
        'media_upload_sessions', 'media_versions', 'media_assets', 'lessons', 'units',
        'courses', 'course_categories', 'parent_links', 'student_profiles',
        'teacher_profiles', 'tenant_user', 'tenant_subscriptions', 'tenant_domains',
        'tenants', 'subscription_packages', 'personal_access_tokens', 'sessions',
        'password_reset_tokens', 'users',
    ];

    private TenantContext $context;

    /** Rolling per-tenant invoice counter (invoices are numbered 1..N per tenant). */
    private int $invoiceSeq = 0;

    public function run(): void
    {
        $this->context = app(TenantContext::class);

        $this->truncateAll();

        DB::transaction(function (): void {
            $packages = $this->seedSubscriptionPackages();
            $admin = $this->seedPlatformAdmin();

            $tenants = [];
            foreach ($this->academyConfigs() as $config) {
                $tenants[] = $this->seedAcademy($config, $packages);
            }

            // A third, closed academy: soft-deleted tenant + canceled subscription,
            // so the lifecycle "end states" (deleted_at / canceled_at) are populated.
            $this->seedClosedAcademy($packages);

            $this->seedPlatformAudit($admin, $tenants);
        });

        $this->command?->info('Seeded platform admin, 2 active academies + 1 closed academy, and every application table.');
    }

    // =====================================================================
    // Truncation
    // =====================================================================

    private function truncateAll(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (self::MANAGED_TABLES as $table) {
            DB::table($table)->truncate();
        }
        Schema::enableForeignKeyConstraints();

        app(TenantContext::class)->forget();
        $this->invoiceSeq = 0;
    }

    // =====================================================================
    // Platform-level (global) data
    // =====================================================================

    /** @return array<string, SubscriptionPackage> keyed by slug */
    private function seedSubscriptionPackages(): array
    {
        $specs = [
            [
                'slug' => 'starter', 'name' => 'باقة البداية',
                'description' => 'باقة تناسب المدرّس المبتدئ: عدد محدود من الطلاب والكورسات مع مساحة تخزين كافية للانطلاق.',
                'price_minor' => 49900, 'interval' => 'monthly', 'trial_days' => 14,
                'limits' => ['max_students' => 200, 'max_courses' => 5, 'storage_mb' => 5120, 'max_assistants' => 1],
                'is_active' => true, 'sort_order' => 1, 'deleted' => false,
            ],
            [
                'slug' => 'pro', 'name' => 'الباقة الاحترافية',
                'description' => 'الأكثر رواجًا: حدود موسّعة للطلاب والكورسات، مساعدون إضافيون ومساحة تخزين كبيرة للفيديوهات.',
                'price_minor' => 129900, 'interval' => 'monthly', 'trial_days' => 30,
                'limits' => ['max_students' => 2000, 'max_courses' => 30, 'storage_mb' => 51200, 'max_assistants' => 5],
                'is_active' => true, 'sort_order' => 2, 'deleted' => false,
            ],
            [
                'slug' => 'elite', 'name' => 'باقة النخبة',
                'description' => 'للأكاديميات الكبرى: طلاب وكورسات بلا حدود، مساحة تخزين ضخمة ودعم بأولوية قصوى.',
                'price_minor' => 999900, 'interval' => 'yearly', 'trial_days' => 0,
                'limits' => ['max_students' => null, 'max_courses' => null, 'storage_mb' => 512000, 'max_assistants' => 20],
                'is_active' => true, 'sort_order' => 3, 'deleted' => false,
            ],
            [
                // Retired plan — populates the soft-delete (deleted_at) column.
                'slug' => 'legacy-basic', 'name' => 'الباقة الأساسية (متوقفة)',
                'description' => 'باقة قديمة لم تعد تُباع للمدرّسين الجدد، محفوظة للاشتراكات السابقة فقط.',
                'price_minor' => 29900, 'interval' => 'monthly', 'trial_days' => 7,
                'limits' => ['max_students' => 100, 'max_courses' => 3, 'storage_mb' => 2048, 'max_assistants' => 0],
                'is_active' => false, 'sort_order' => 99, 'deleted' => true,
            ],
        ];

        $packages = [];
        foreach ($specs as $spec) {
            $deleted = $spec['deleted'];
            unset($spec['deleted']);

            $package = new SubscriptionPackage($spec);
            $package->currency = 'EGP';
            $package->save();

            if ($deleted) {
                $package->deleted_at = now()->subMonths(3);
                $package->saveQuietly();
            }
            $packages[$spec['slug']] = $package;
        }

        return $packages;
    }

    private function seedPlatformAdmin(): User
    {
        $admin = $this->makeUser([
            'name' => 'إدارة منصة العميد',
            'email' => 'admin@elameed.app',
            'phone' => '01000000000',
            'is_platform_admin' => true,
        ]);

        DB::table('password_reset_tokens')->insert([
            'email' => $admin->email,
            'token' => Str::random(64),
            'created_at' => now()->subMinutes(9),
        ]);

        $this->makeSession($admin, '196.221.10.5', now()->subMinutes(3));
        $this->makeAccessToken($admin, 'admin-console', ['*'], now()->subDay(), now()->addYear());

        $this->makeLoginAttempt($admin, null, $admin->phone, '196.221.10.5', true, now()->subMinutes(4));
        $this->makeLoginAttempt(null, null, '01099999999', '102.44.8.9', false, now()->subMinutes(50));

        $this->makeOtp($admin->phone, 'sms', 'login', 2, now()->subMinutes(1), now()->subMinutes(6));
        $this->makeOtp($admin->email, 'email', 'reset', 0, now()->addMinutes(10), null);

        return $admin;
    }

    /** @param  array<int, Tenant>  $tenants */
    private function seedPlatformAudit(User $admin, array $tenants): void
    {
        $this->makeAudit(null, $admin, 'platform.login', User::class, $admin->id, ['ip' => '196.221.10.5'], '196.221.10.5', now()->subMinutes(4));
        foreach ($tenants as $i => $tenant) {
            $this->makeAudit(
                $tenant->id, $admin, 'tenant.subscription.assigned', Tenant::class, $tenant->id,
                ['slug' => $tenant->slug, 'actor' => 'platform-admin'], '196.221.10.5', now()->subDays(30 - $i),
            );
        }
    }

    /**
     * A minimal closed academy: an owner + suspended membership + domain + a
     * CANCELED subscription, then the tenant is soft-deleted. This is the one
     * place the tenant/subscription lifecycle "end states" (tenants.deleted_at
     * and tenant_subscriptions.canceled_at) are exercised.
     *
     * @param  array<string, SubscriptionPackage>  $packages
     */
    private function seedClosedAcademy(array $packages): void
    {
        $package = $packages['legacy-basic'];

        $owner = $this->makeUser([
            'name' => 'أ. تجريبي (أكاديمية مغلقة)',
            'email' => 'closed.academy.teacher@example.com',
            'phone' => '01099000001',
        ]);

        $tenant = new Tenant([
            'slug' => 'closed-academy',
            'name' => 'أكاديمية سابقة (مغلقة)',
            'status' => 'expired',
            'owner_user_id' => $owner->id,
            'dedicated_db_connection' => 'shared',
            'package_id' => $package->id,
            'trial_ends_at' => now()->subMonths(14)->addDays($package->trial_days),
        ]);
        $tenant->save();

        $this->addMembership($tenant, $owner, 'teacher', 'suspended', now()->subMonths(14));

        TenantDomain::create([
            'tenant_id' => $tenant->id,
            'host' => 'closed-academy.elameed.app',
            'type' => 'subdomain',
            'is_primary' => true,
            'cf_custom_hostname_id' => 'cf_'.Str::lower(Str::random(24)),
            'ssl_status' => 'revoked',
            'verified_at' => now()->subMonths(14),
        ]);

        $sub = new TenantSubscription([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'status' => 'canceled',
            'price_minor' => $package->price_minor,
            'started_at' => now()->subMonths(14),
            'trial_ends_at' => now()->subMonths(14)->addDays($package->trial_days),
            'renews_at' => null,
            'ends_at' => now()->subMonths(2),
            'canceled_at' => now()->subMonths(3),
            'meta' => ['reason' => 'توقّف المدرّس عن التدريس على المنصة', 'closed_by' => 'platform-admin'],
        ]);
        $sub->currency = 'EGP';
        $sub->save();

        // Soft-delete the tenant → populates tenants.deleted_at.
        $tenant->deleted_at = now()->subMonths(2);
        $tenant->saveQuietly();
    }

    // =====================================================================
    // Academy configuration
    // =====================================================================

    /** @return array<int, array<string, mixed>> */
    private function academyConfigs(): array
    {
        return [
            [
                'slug' => 'farag-physics',
                'name' => 'أكاديمية الأستاذ محمود فرّاج للفيزياء',
                'subject' => 'الفيزياء',
                'package' => 'pro',
                'primary_color' => '#0D47A1',
                'secondary_color' => '#FFC107',
                'base' => '01010',
                'teacher' => ['name' => 'الأستاذ محمود فرّاج', 'role' => 'مدرّس الفيزياء للثانوية العامة'],
                'assistants' => ['أ. كريم عادل', 'أ. دعاء منصور'],
                'centers' => [
                    ['name' => 'سنتر النخبة - المعادي', 'address' => '١٢ شارع ٩، المعادي، القاهرة', 'active' => true],
                    ['name' => 'سنتر مدينة نصر (مغلق مؤقتًا)', 'address' => 'مكرم عبيد، مدينة نصر، القاهرة', 'active' => false],
                ],
                'categories' => [
                    ['name' => 'الصف الثالث الثانوي', 'grade' => 'الثالث الثانوي', 'subject' => 'فيزياء', 'level' => 'متقدم', 'section' => 'علمي'],
                    ['name' => 'الصف الثاني الثانوي', 'grade' => 'الثاني الثانوي', 'subject' => 'فيزياء', 'level' => 'متوسط', 'section' => 'علمي'],
                    ['name' => 'مراجعات نهائية', 'grade' => 'الثالث الثانوي', 'subject' => 'فيزياء', 'level' => 'مكثّف', 'section' => 'عام'],
                ],
                'courses' => $this->physicsCourses(),
            ],
            [
                'slug' => 'sara-chemistry',
                'name' => 'منصة الكيمياء مع د. سارة عبد الرحمن',
                'subject' => 'الكيمياء',
                'package' => 'starter',
                'primary_color' => '#1B5E20',
                'secondary_color' => '#F48FB1',
                'base' => '01020',
                'teacher' => ['name' => 'د. سارة عبد الرحمن', 'role' => 'دكتوراة الكيمياء ومدرّسة الثانوية العامة'],
                'assistants' => ['أ. منة الله رضا', 'أ. عبد الله شاكر'],
                'centers' => [
                    ['name' => 'سنتر الأوائل - طنطا', 'address' => 'شارع الجيش، طنطا، الغربية', 'active' => true],
                    ['name' => 'سنتر المنصورة (أرشيف)', 'address' => 'شارع الجمهورية، المنصورة، الدقهلية', 'active' => false],
                ],
                'categories' => [
                    ['name' => 'الصف الثالث الثانوي', 'grade' => 'الثالث الثانوي', 'subject' => 'كيمياء', 'level' => 'متقدم', 'section' => 'علمي علوم'],
                    ['name' => 'الصف الثاني الثانوي', 'grade' => 'الثاني الثانوي', 'subject' => 'كيمياء', 'level' => 'متوسط', 'section' => 'علمي'],
                    ['name' => 'كيمياء تفاعلية', 'grade' => 'الثالث الثانوي', 'subject' => 'كيمياء', 'level' => 'إثرائي', 'section' => 'عام'],
                ],
                'courses' => $this->chemistryCourses(),
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function physicsCourses(): array
    {
        return [
            [
                'title' => 'الفيزياء الحديثة - الصف الثالث الثانوي', 'subtitle' => 'الكم والنسبية وفيزياء الجسيمات في منهج بسيط ومترابط',
                'slug' => 'modern-physics-g3', 'category' => 0, 'price_minor' => 45000, 'is_free' => false, 'is_center' => false,
                'access_days' => 180, 'points' => 100, 'visibility' => 'visible', 'deleted' => false,
                'outcomes' => ['فهم ازدواجية الموجة والجسيم', 'حل مسائل الظاهرة الكهروضوئية', 'تفسير أطياف الذرات', 'إتقان أسئلة الامتحان النهائي'],
                'requirements' => ['إتقان أساسيات الميكانيكا', 'معرفة أولية بالكهربية', 'آلة حاسبة علمية'],
                'audience' => ['طلاب الثانوية العامة - الشعبة العلمية', 'الراغبون في مراجعة الفيزياء الحديثة'],
                'units' => [
                    ['title' => 'الوحدة الأولى: الطبيعة الموجية والجسيمية', 'lessons' => [
                        ['title' => 'مقدمة الفيزياء الحديثة', 'source' => 'youtube', 'duration' => 720, 'preview' => true],
                        ['title' => 'الظاهرة الكهروضوئية', 'source' => 'upload', 'duration' => 1560, 'preview' => false],
                        ['title' => 'تأثير كومبتون', 'source' => 'upload', 'duration' => 1320, 'preview' => false],
                    ]],
                    ['title' => 'الوحدة الثانية: نموذج بور والأطياف', 'lessons' => [
                        ['title' => 'نموذج بور للذرة', 'source' => 'upload', 'duration' => 1800, 'preview' => false],
                        ['title' => 'أطياف الانبعاث والامتصاص', 'source' => 'youtube', 'duration' => 990, 'preview' => false],
                    ]],
                ],
                'exams' => 'full',
            ],
            [
                'title' => 'ميكانيكا الكم المبسّطة', 'subtitle' => 'من مبدأ عدم اليقين إلى معادلة شرودنجر بأسلوب مُيسّر',
                'slug' => 'quantum-mechanics-simplified', 'category' => 2, 'price_minor' => 60000, 'is_free' => false, 'is_center' => false,
                'access_days' => 365, 'points' => 150, 'visibility' => 'visible', 'deleted' => false,
                'outcomes' => ['استيعاب مبدأ عدم اليقين لهايزنبرج', 'التعامل مع الدالة الموجية', 'تطبيقات الكم في الإلكترونيات'],
                'requirements' => ['اجتياز كورس الفيزياء الحديثة أو ما يعادله'],
                'audience' => ['المتفوقون الراغبون في التوسّع', 'طلاب الأولمبياد العلمي'],
                'units' => [
                    ['title' => 'الوحدة الأولى: أسس الكم', 'lessons' => [
                        ['title' => 'مبدأ عدم اليقين', 'source' => 'upload', 'duration' => 1440, 'preview' => true],
                        ['title' => 'الدالة الموجية', 'source' => 'upload', 'duration' => 1620, 'preview' => false],
                    ]],
                    ['title' => 'الوحدة الثانية: تطبيقات', 'lessons' => [
                        ['title' => 'البئر الجهدي', 'source' => 'youtube', 'duration' => 1080, 'preview' => false],
                    ]],
                ],
                'exams' => 'bubble',
            ],
            [
                'title' => 'مقدمة مجانية في الفيزياء', 'subtitle' => 'ابدأ رحلتك مع الفيزياء خطوة بخطوة مجانًا',
                'slug' => 'physics-intro-free', 'category' => 1, 'price_minor' => 0, 'is_free' => true, 'is_center' => false,
                'access_days' => null, 'points' => 20, 'visibility' => 'visible', 'deleted' => false,
                'outcomes' => ['التعرّف على فروع الفيزياء', 'أهمية الوحدات والقياس'],
                'requirements' => ['لا يوجد - الكورس للمبتدئين'],
                'audience' => ['كل طالب جديد على المنصة'],
                'units' => [
                    ['title' => 'الوحدة التمهيدية', 'lessons' => [
                        ['title' => 'ما هي الفيزياء؟', 'source' => 'youtube', 'duration' => 600, 'preview' => true],
                        ['title' => 'القياس والوحدات', 'source' => 'youtube', 'duration' => 540, 'preview' => true],
                    ]],
                ],
                'exams' => 'none',
            ],
            [
                'title' => 'كورس السنتر - فيزياء (حضوري + أونلاين)', 'subtitle' => 'المحتوى المصاحب لطلاب السنتر مع كود التفعيل',
                'slug' => 'center-physics', 'category' => 0, 'price_minor' => 30000, 'is_free' => false, 'is_center' => true,
                'access_days' => 120, 'points' => 60, 'visibility' => 'visible', 'deleted' => false,
                'outcomes' => ['متابعة شرح السنتر أونلاين', 'حل الواجبات الأسبوعية'],
                'requirements' => ['كود تفعيل من السنتر'],
                'audience' => ['طلاب سناتر الأستاذ محمود فرّاج'],
                'units' => [
                    ['title' => 'حصص الأسبوع الأول', 'lessons' => [
                        ['title' => 'حصة السنتر ١', 'source' => 'upload', 'duration' => 3600, 'preview' => false],
                    ]],
                ],
                'exams' => 'none',
            ],
            [
                'title' => 'أرشيف: الكهربية ٢٠٢٣ (متوقف)', 'subtitle' => 'نسخة قديمة محفوظة للأرشيف',
                'slug' => 'archive-electricity-2023', 'category' => 1, 'price_minor' => 25000, 'is_free' => false, 'is_center' => false,
                'access_days' => 90, 'points' => 40, 'visibility' => 'hidden', 'deleted' => true,
                'outcomes' => ['محتوى مؤرشف'], 'requirements' => ['—'], 'audience' => ['—'],
                'units' => [
                    ['title' => 'وحدة مؤرشفة', 'lessons' => [
                        ['title' => 'درس مؤرشف', 'source' => 'youtube', 'duration' => 480, 'preview' => false],
                    ]],
                ],
                'exams' => 'none',
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function chemistryCourses(): array
    {
        return [
            [
                'title' => 'الكيمياء العضوية - الصف الثالث الثانوي', 'subtitle' => 'الهيدروكربونات والمجموعات الوظيفية بشرح تفاعلي',
                'slug' => 'organic-chemistry-g3', 'category' => 0, 'price_minor' => 50000, 'is_free' => false, 'is_center' => false,
                'access_days' => 200, 'points' => 120, 'visibility' => 'visible', 'deleted' => false,
                'outcomes' => ['تسمية المركبات العضوية', 'التمييز بين المجموعات الوظيفية', 'كتابة معادلات التفاعلات العضوية'],
                'requirements' => ['أساسيات الكيمياء العامة', 'الجدول الدوري'],
                'audience' => ['طلاب الثانوية العامة - علمي علوم'],
                'units' => [
                    ['title' => 'الوحدة الأولى: الهيدروكربونات', 'lessons' => [
                        ['title' => 'الألكانات', 'source' => 'youtube', 'duration' => 840, 'preview' => true],
                        ['title' => 'الألكينات والألكاينات', 'source' => 'upload', 'duration' => 1500, 'preview' => false],
                    ]],
                    ['title' => 'الوحدة الثانية: المجموعات الوظيفية', 'lessons' => [
                        ['title' => 'الكحولات', 'source' => 'upload', 'duration' => 1680, 'preview' => false],
                        ['title' => 'الأحماض الكربوكسيلية', 'source' => 'youtube', 'duration' => 1200, 'preview' => false],
                    ]],
                ],
                'exams' => 'full',
            ],
            [
                'title' => 'الكيمياء غير العضوية', 'subtitle' => 'العناصر الانتقالية والاتزان الكيميائي',
                'slug' => 'inorganic-chemistry', 'category' => 2, 'price_minor' => 55000, 'is_free' => false, 'is_center' => false,
                'access_days' => 300, 'points' => 130, 'visibility' => 'visible', 'deleted' => false,
                'outcomes' => ['فهم خواص العناصر الانتقالية', 'حساب ثابت الاتزان', 'تطبيقات التحليل الكيميائي'],
                'requirements' => ['الكيمياء العضوية أو ما يعادلها'],
                'audience' => ['المتفوقون في الكيمياء'],
                'units' => [
                    ['title' => 'الوحدة الأولى: العناصر الانتقالية', 'lessons' => [
                        ['title' => 'خواص العناصر الانتقالية', 'source' => 'upload', 'duration' => 1560, 'preview' => true],
                    ]],
                    ['title' => 'الوحدة الثانية: الاتزان', 'lessons' => [
                        ['title' => 'الاتزان الكيميائي', 'source' => 'upload', 'duration' => 1740, 'preview' => false],
                    ]],
                ],
                'exams' => 'bubble',
            ],
            [
                'title' => 'أساسيات الكيمياء (مجاني)', 'subtitle' => 'مدخل مجاني لعالم الكيمياء',
                'slug' => 'chemistry-basics-free', 'category' => 1, 'price_minor' => 0, 'is_free' => true, 'is_center' => false,
                'access_days' => null, 'points' => 15, 'visibility' => 'visible', 'deleted' => false,
                'outcomes' => ['تركيب الذرة', 'قراءة الجدول الدوري'],
                'requirements' => ['لا يوجد'],
                'audience' => ['المبتدئون في الكيمياء'],
                'units' => [
                    ['title' => 'الوحدة التمهيدية', 'lessons' => [
                        ['title' => 'تركيب الذرة', 'source' => 'youtube', 'duration' => 660, 'preview' => true],
                        ['title' => 'الجدول الدوري', 'source' => 'youtube', 'duration' => 720, 'preview' => true],
                    ]],
                ],
                'exams' => 'none',
            ],
            [
                'title' => 'كورس السنتر - كيمياء', 'subtitle' => 'محتوى سنتر طنطا للطلاب الحضوريين',
                'slug' => 'center-chemistry', 'category' => 0, 'price_minor' => 35000, 'is_free' => false, 'is_center' => true,
                'access_days' => 120, 'points' => 60, 'visibility' => 'visible', 'deleted' => false,
                'outcomes' => ['متابعة حصص السنتر', 'حل الاختبارات الدورية'],
                'requirements' => ['كود تفعيل من السنتر'],
                'audience' => ['طلاب سنتر الأوائل بطنطا'],
                'units' => [
                    ['title' => 'حصص الأسبوع الأول', 'lessons' => [
                        ['title' => 'حصة السنتر ١', 'source' => 'upload', 'duration' => 3300, 'preview' => false],
                    ]],
                ],
                'exams' => 'none',
            ],
            [
                'title' => 'أرشيف: كيمياء ٢٠٢٣ (متوقف)', 'subtitle' => 'نسخة قديمة محفوظة للأرشيف',
                'slug' => 'archive-chemistry-2023', 'category' => 1, 'price_minor' => 20000, 'is_free' => false, 'is_center' => false,
                'access_days' => 90, 'points' => 30, 'visibility' => 'hidden', 'deleted' => true,
                'outcomes' => ['محتوى مؤرشف'], 'requirements' => ['—'], 'audience' => ['—'],
                'units' => [
                    ['title' => 'وحدة مؤرشفة', 'lessons' => [
                        ['title' => 'درس مؤرشف', 'source' => 'youtube', 'duration' => 500, 'preview' => false],
                    ]],
                ],
                'exams' => 'none',
            ],
        ];
    }

    // =====================================================================
    // Per-academy seeding
    // =====================================================================

    /** @param  array<string, SubscriptionPackage>  $packages */
    private function seedAcademy(array $config, array $packages): Tenant
    {
        $base = $config['base'];
        $package = $packages[$config['package']];

        // --- Tenant + subscription + domains -----------------------------
        $teacher = $this->makeUser([
            'name' => $config['teacher']['name'],
            'email' => $config['slug'].'.teacher@example.com',
            'phone' => $base.'00001',
        ]);

        $tenant = new Tenant([
            'slug' => $config['slug'],
            'name' => $config['name'],
            'status' => 'active',
            'owner_user_id' => $teacher->id,
            'dedicated_db_connection' => 'shared',
            'package_id' => $package->id,
            'trial_ends_at' => now()->addDays(12),
        ]);
        $tenant->save();

        $this->context->setTenant($tenant);

        $this->seedTenantSubscription($tenant, $package);
        $this->seedDomains($tenant, $config);

        // --- People -------------------------------------------------------
        $this->addMembership($tenant, $teacher, 'teacher', 'active', now()->subMonths(8));
        $this->seedTeacherProfile($tenant, $config);

        $assistants = [];
        foreach ($config['assistants'] as $i => $name) {
            $assistant = $this->makeUser([
                'name' => $name,
                'email' => $config['slug'].".assistant{$i}@example.com",
                'phone' => $base.'000'.(11 + $i),
            ]);
            $this->addMembership($tenant, $assistant, 'assistant', 'active', now()->subMonths(6 - $i));
            $assistants[] = $assistant;
        }

        $students = $this->seedStudents($tenant, $config, $base);
        $this->seedParents($tenant, $config, $base, $students);

        // --- Catalogue ----------------------------------------------------
        $categories = [];
        foreach ($config['categories'] as $i => $cat) {
            $categories[] = CourseCategory::create([
                'name' => $cat['name'], 'grade' => $cat['grade'], 'subject' => $cat['subject'],
                'level' => $cat['level'], 'section' => $cat['section'], 'sort_order' => $i + 1,
            ]);
        }

        $built = $this->seedCourses($tenant, $config, $categories, $teacher);
        $courses = $built['courses'];
        $lessonsByCourse = $built['lessons'];
        $unitsByCourse = $built['units'];
        $examsByCourse = $built['exams'];

        // --- Media extras (variety states) + callback event ---------------
        $this->seedMediaExtras($tenant, $courses, $lessonsByCourse, $teacher, $students);

        // --- Bundles ------------------------------------------------------
        $bundles = $this->seedBundles($tenant, $courses, $unitsByCourse, $lessonsByCourse);

        // --- Centres, activation codes, attendance ------------------------
        $centers = $this->seedCenters($tenant, $config);
        $centerCourse = $this->firstCenterCourse($courses);
        $this->seedActivationCodes($tenant, $centers, $centerCourse, $students);
        $this->seedAttendance($tenant, $centers, $centerCourse, $students, $assistants[0]);

        // --- Commerce, wallets, enrollments -------------------------------
        $this->seedCommerce($tenant, $config, $teacher, $students, $courses, $bundles, $unitsByCourse, $lessonsByCourse);

        // --- Engagement ---------------------------------------------------
        $this->seedEngagement($tenant, $courses, $lessonsByCourse, $examsByCourse, $students, $teacher);

        // --- Notifications + per-tenant auth/audit ------------------------
        $this->seedNotifications($tenant, $students, $teacher, $courses);
        $this->seedTenantAuthTrail($tenant, $teacher, $students, $base);

        $this->context->forget();

        return $tenant;
    }

    private function seedTenantSubscription(Tenant $tenant, SubscriptionPackage $package): void
    {
        $sub = new TenantSubscription([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'status' => 'active',
            'price_minor' => $package->price_minor,
            'started_at' => now()->subMonths(2),
            'trial_ends_at' => now()->subMonths(2)->addDays($package->trial_days),
            'renews_at' => now()->addMonth(),
            'ends_at' => now()->addYear(),
            'canceled_at' => null,
            'meta' => ['discount_reason' => 'خصم المدرّس الجديد 10%', 'assigned_by' => 'platform-admin', 'channel' => 'manual'],
        ]);
        $sub->currency = 'EGP';
        $sub->save();
    }

    private function seedDomains(Tenant $tenant, array $config): void
    {
        TenantDomain::create([
            'tenant_id' => $tenant->id,
            'host' => $config['slug'].'.elameed.app',
            'type' => 'subdomain',
            'is_primary' => true,
            'cf_custom_hostname_id' => 'cf_'.Str::lower(Str::random(24)),
            'ssl_status' => 'active',
            'verified_at' => now()->subMonths(2),
        ]);

        TenantDomain::create([
            'tenant_id' => $tenant->id,
            'host' => 'www.'.str_replace('-', '', $config['slug']).'.com',
            'type' => 'custom',
            'is_primary' => false,
            'cf_custom_hostname_id' => 'cf_'.Str::lower(Str::random(24)),
            'ssl_status' => 'pending_validation',
            'verified_at' => null,
        ]);
    }

    private function seedTeacherProfile(Tenant $tenant, array $config): void
    {
        TeacherProfile::create([
            'logo_url' => 'https://cdn.elameed.app/'.$config['slug'].'/logo.png',
            'cover_url' => 'https://cdn.elameed.app/'.$config['slug'].'/cover.jpg',
            'primary_color' => $config['primary_color'],
            'secondary_color' => $config['secondary_color'],
            'bio' => 'أكاديمية متخصصة في تدريس '.$config['subject'].' للثانوية العامة، بأسلوب مبسّط وشرح متدرّج، مع بنك أسئلة ومتابعة مستمرة لأولياء الأمور.',
            'contact' => [
                'phone' => $config['base'].'00001',
                'email' => $config['slug'].'@example.com',
                'whatsapp' => $config['base'].'00001',
                'address' => 'جمهورية مصر العربية - القاهرة',
            ],
            'socials' => [
                'facebook' => 'https://facebook.com/'.$config['slug'],
                'youtube' => 'https://youtube.com/@'.$config['slug'],
                'instagram' => 'https://instagram.com/'.$config['slug'],
                'tiktok' => 'https://tiktok.com/@'.$config['slug'],
                'telegram' => 'https://t.me/'.$config['slug'],
            ],
            'landing_sections' => $this->buildLanding($config),
            'locales' => ['ar', 'en'],
            'primary_locale' => 'ar',
            'layout' => 'classic',
            'hide_ranking' => false,
            'login_enabled' => true,
            'registration_enabled' => true,
        ]);
    }

    /** @return array<int, User> */
    private function seedStudents(Tenant $tenant, array $config, string $base): array
    {
        // name, gender, governorate, region, academic_year, education_type, membership status
        $roster = $config['slug'] === 'farag-physics'
            ? [
                ['أحمد سمير', 'ذكر', 'القاهرة', 'المعادي', 'الصف الثالث الثانوي', 'عام', 'active'],
                ['منة الله حسن', 'أنثى', 'الجيزة', 'الدقي', 'الصف الثالث الثانوي', 'عام', 'active'],
                ['يوسف خالد', 'ذكر', 'الإسكندرية', 'سموحة', 'الصف الثاني الثانوي', 'عام', 'active'],
                ['سارة إبراهيم', 'أنثى', 'القاهرة', 'مدينة نصر', 'الصف الثالث الثانوي', 'أزهري', 'active'],
                ['عمر طارق', 'ذكر', 'القليوبية', 'بنها', 'الصف الثالث الثانوي', 'عام', 'suspended'],
                ['ندى محمود', 'أنثى', 'الجيزة', '٦ أكتوبر', 'الصف الثاني الثانوي', 'عام', 'pending'],
            ]
            : [
                ['مريم علاء', 'أنثى', 'الغربية', 'طنطا', 'الصف الثالث الثانوي', 'عام', 'active'],
                ['زياد فتحي', 'ذكر', 'الدقهلية', 'المنصورة', 'الصف الثالث الثانوي', 'عام', 'active'],
                ['حبيبة ياسر', 'أنثى', 'القاهرة', 'حلوان', 'الصف الثاني الثانوي', 'أزهري', 'active'],
                ['مازن وليد', 'ذكر', 'الإسكندرية', 'العجمي', 'الصف الثالث الثانوي', 'عام', 'suspended'],
                ['جنى أشرف', 'أنثى', 'الغربية', 'المحلة الكبرى', 'الصف الثالث الثانوي', 'عام', 'active'],
            ];

        $students = [];
        foreach ($roster as $i => $r) {
            $n = 101 + $i;
            $student = $this->makeUser([
                'name' => $r[0],
                'email' => $config['slug'].".student{$i}@example.com",
                'phone' => $base.'00'.$n,
            ]);
            $this->addMembership($tenant, $student, 'student', $r[6], now()->subMonths(5)->addDays($i));

            StudentProfile::create([
                'user_id' => $student->id,
                'gender' => $r[1],
                'governorate' => $r[2],
                'region' => $r[3],
                'academic_year' => $r[4],
                'education_type' => $r[5],
                'guardian_phone' => $base.'09'.$n,
            ]);

            // Every student gets a wallet (BelongsToTenant → tenant_id auto).
            Wallet::create(['user_id' => $student->id, 'currency' => 'EGP']);

            $students[] = $student;
        }

        return $students;
    }

    /** @param  array<int, User>  $students */
    private function seedParents(Tenant $tenant, array $config, string $base, array $students): void
    {
        $parents = $config['slug'] === 'farag-physics'
            ? [['سمير عبد الله (والد أحمد)', 'father', 0], ['هدى فؤاد (والدة منة الله)', 'mother', 1]]
            : [['علاء الدين محمد (والد مريم)', 'father', 0], ['فاطمة صبري (والدة حبيبة)', 'guardian', 2]];

        foreach ($parents as $i => $p) {
            $parent = $this->makeUser([
                'name' => $p[0],
                'email' => $config['slug'].".parent{$i}@example.com",
                'phone' => $base.'002'.($i + 1),
            ]);
            $this->addMembership($tenant, $parent, 'parent', 'active', now()->subMonths(4));

            ParentLink::create([
                'parent_user_id' => $parent->id,
                'student_user_id' => $students[$p[2]]->id,
                'relation' => $p[1],
            ]);
        }
    }

    // =====================================================================
    // Catalogue: courses → units → lessons → media → exams
    // =====================================================================

    /**
     * @param  array<int, CourseCategory>  $categories
     * @return array{courses: array<int, Course>, units: array<int, array<int, Unit>>, lessons: array<int, array<int, Lesson>>, exams: array<int, array<int, Exam>>}
     */
    private function seedCourses(Tenant $tenant, array $config, array $categories, User $teacher): array
    {
        $courses = [];
        $unitsByCourse = [];
        $lessonsByCourse = [];
        $examsByCourse = [];

        foreach ($config['courses'] as $ci => $spec) {
            $course = new Course([
                'title' => $spec['title'],
                'subtitle' => $spec['subtitle'],
                'slug' => $spec['slug'],
                'description' => 'كورس '.$spec['title'].' يقدّم شرحًا وافيًا مدعّمًا بالأمثلة والتطبيقات وبنك أسئلة متكامل، مع إمكانية إعادة المشاهدة ومتابعة التقدّم.',
                'learning_outcomes' => $spec['outcomes'],
                'requirements' => $spec['requirements'],
                'audience' => $spec['audience'],
                'parts' => [],
                'category_id' => $categories[$spec['category']]->id,
                'price_minor' => $spec['price_minor'],
                'currency' => 'EGP',
                'access_days' => $spec['access_days'],
                'visibility' => $spec['visibility'],
                'publish_at' => now()->subMonths(3)->addDays($ci),
                'is_free' => $spec['is_free'],
                'purchase_enabled' => ! $spec['is_free'],
                'is_center' => $spec['is_center'],
                'cover_url' => 'https://cdn.elameed.app/'.$config['slug'].'/courses/'.$spec['slug'].'-cover.jpg',
                'thumbnail_url' => 'https://cdn.elameed.app/'.$config['slug'].'/courses/'.$spec['slug'].'-thumb.jpg',
                'promo_video_url' => 'https://youtu.be/'.Str::lower(Str::random(11)),
                'points' => $spec['points'],
            ]);
            $course->save();

            $units = [];
            $lessons = [];
            $parts = [];
            foreach ($spec['units'] as $ui => $unitSpec) {
                $unit = Unit::create([
                    'course_id' => $course->id,
                    'title' => $unitSpec['title'],
                    'sort_order' => $ui + 1,
                    'visibility' => 'visible',
                    'publish_at' => now()->subMonths(3)->addDays($ci + $ui),
                ]);
                $units[] = $unit;

                $unitDuration = 0;
                foreach ($unitSpec['lessons'] as $li => $lessonSpec) {
                    $lesson = $this->createLesson($course, $unit, $lessonSpec, $li, $config, $teacher);
                    $lessons[] = $lesson;
                    $unitDuration += (int) $lessonSpec['duration'];
                }

                $parts[] = [
                    'title' => $unitSpec['title'],
                    'lessons_count' => count($unitSpec['lessons']),
                    'duration_min' => (int) round($unitDuration / 60),
                ];
            }

            // Backfill the marketing "parts" summary now that lessons exist.
            $course->parts = $parts;
            $course->save();

            if ($spec['deleted']) {
                $course->deleted_at = now()->subMonths(2);
                $course->saveQuietly();
            }

            $exams = $this->seedExams($course, $categories[$spec['category']], $spec['exams'], $lessons[0] ?? null);

            $courses[$ci] = $course;
            $unitsByCourse[$ci] = $units;
            $lessonsByCourse[$ci] = $lessons;
            $examsByCourse[$ci] = $exams;
        }

        return ['courses' => $courses, 'units' => $unitsByCourse, 'lessons' => $lessonsByCourse, 'exams' => $examsByCourse];
    }

    private function createLesson(Course $course, Unit $unit, array $spec, int $index, array $config, User $teacher): Lesson
    {
        $isYoutube = $spec['source'] === 'youtube';

        $lesson = new Lesson([
            'unit_id' => $unit->id,
            'course_id' => $course->id,
            'title' => $spec['title'],
            'description' => 'شرح تفصيلي لدرس «'.$spec['title'].'» مع أمثلة محلولة وتدريبات على نمط الامتحان.',
            'sort_order' => $index + 1,
            'youtube_url' => $isYoutube ? 'https://www.youtube.com/watch?v='.Str::lower(Str::random(11)) : null,
            'active_video_source' => $spec['source'],
            'duration_sec' => (int) $spec['duration'],
            'max_views' => $config['slug'] === 'farag-physics' ? 3 : 5,
            'is_free_preview' => (bool) $spec['preview'],
            'gating_rule' => ['requires_exam_id' => null, 'min_progress_percent' => 0],
            'visibility' => 'visible',
            'publish_at' => now()->subMonths(2)->addDays($index),
        ]);
        $lesson->save();

        if (! $isYoutube) {
            // Uploaded lessons get a protected HLS video asset + ready version.
            $asset = $this->createVideoAsset($lesson, $spec, $teacher);
            $lesson->video_asset_id = $asset->id;
            $lesson->save();
        }

        // Every first lesson of a unit carries a PDF handout + an external link,
        // populating the non-video media_assets columns (url, watermark, scope…).
        if ($index === 0) {
            $this->createAttachment($lesson, 'pdf', 'ملخّص PDF - '.$lesson->title, 1);
            $this->createAttachment($lesson, 'link', 'مصدر إثرائي خارجي - '.$lesson->title, 2);
        }

        return $lesson;
    }

    private function createVideoAsset(Lesson $lesson, array $spec, User $teacher): MediaAsset
    {
        $duration = (int) $spec['duration'];

        $asset = new MediaAsset([
            'lesson_id' => $lesson->id,
            'type' => 'hls_video',
            'status' => 'ready',
            'provider' => 'remote',
            'current_version_id' => null,
            'thumbnail_url' => 'https://cdn.elameed.app/thumbs/'.Str::lower(Str::random(12)).'.jpg',
            'title' => $lesson->title.' (فيديو)',
            'source_key' => 'originals/'.Str::uuid()->toString().'.mp4',
            'hls_path' => 'hls/'.Str::lower(Str::random(16)).'/index.m3u8',
            'encryption_key_ref' => 'kms://elameed/keys/'.Str::lower(Str::random(20)),
            'renditions' => [
                ['height' => 360, 'bandwidth' => 800000, 'codecs' => 'avc1.4d401e'],
                ['height' => 720, 'bandwidth' => 2500000, 'codecs' => 'avc1.4d401f'],
                ['height' => 1080, 'bandwidth' => 5000000, 'codecs' => 'avc1.640028'],
            ],
            'duration_sec' => $duration,
            'url' => null,
            'watermark_policy' => 'dynamic_overlay',
            'downloadable' => false,
            'access_scope' => 'enrolled',
            'sort_order' => 1,
        ]);
        $asset->save();

        $version = MediaVersion::create([
            'media_asset_id' => $asset->id,
            'version' => 1,
            'provider' => 'remote',
            'state' => 'ready',
            'host_video_id' => 'vid_'.Str::lower(Str::random(18)),
            'playback_id' => 'pb_'.Str::lower(Str::random(18)),
            'thumbnail_url' => $asset->thumbnail_url,
            'duration_sec' => $duration,
            'meta' => ['width' => 1920, 'height' => 1080, 'codec' => 'h264', 'bitrate_kbps' => 4200, 'segments' => (int) ceil($duration / 6)],
            'error' => null,
            'ready_at' => now()->subMonths(2),
        ]);

        MediaUploadSession::create([
            'media_version_id' => $version->id,
            'created_by' => $teacher->id,
            'idempotency_key' => 'ups_'.Str::lower(Str::random(40)),
            'host_upload_id' => 'up_'.Str::lower(Str::random(18)),
            'upload_url' => 'https://uploads.media-host.example/'.Str::lower(Str::random(24)),
            'protocol' => 'tus',
            'size_bytes' => $duration * 520000,
            'max_bytes' => 5368709120,
            'content_type' => 'video/mp4',
            'checksum_sha256' => hash('sha256', $asset->source_key),
            'state' => 'verified',
            'expires_at' => now()->subMonths(2)->addDay(),
        ]);

        $asset->current_version_id = $version->id;
        $asset->save();

        return $asset;
    }

    private function createAttachment(Lesson $lesson, string $type, string $title, int $sort): MediaAsset
    {
        $isPdf = $type === 'pdf';

        return MediaAsset::create([
            'lesson_id' => $lesson->id,
            'type' => $type,
            'status' => 'ready',
            'provider' => 'local',
            'current_version_id' => null,
            'thumbnail_url' => $isPdf ? 'https://cdn.elameed.app/thumbs/pdf.png' : 'https://cdn.elameed.app/thumbs/link.png',
            'title' => $title,
            'source_key' => $isPdf ? 'attachments/'.Str::uuid()->toString().'.pdf' : null,
            'hls_path' => null,
            'encryption_key_ref' => null,
            'renditions' => null,
            'duration_sec' => null,
            'url' => $isPdf
                ? 'https://cdn.elameed.app/files/'.Str::lower(Str::random(14)).'.pdf'
                : 'https://ar.wikipedia.org/wiki/'.rawurlencode($lesson->title),
            'watermark_policy' => $isPdf ? 'footer_stamp' : 'none',
            'downloadable' => $isPdf,
            'access_scope' => $isPdf ? 'enrolled' : 'public',
            'sort_order' => $sort,
        ]);
    }

    /** @return array<int, Exam> */
    private function seedExams(Course $course, CourseCategory $category, string $kind, ?Lesson $lesson = null): array
    {
        if ($kind === 'none') {
            return [];
        }

        $exams = [];

        if ($kind === 'full') {
            $quiz = Exam::create([
                'course_id' => $course->id,
                'lesson_id' => null,
                'title' => 'امتحان الوحدة الأولى - '.$course->title,
                'type' => 'exam',
                'pass_percent' => 60,
                'duration_min' => 45,
                'attempts_allowed' => 2,
                'question_order' => 'random',
                'scoring' => 'best',
                'starts_at' => now()->subMonth(),
                'ends_at' => now()->addMonth(),
                'result_visibility' => 'immediate',
                'show_answers' => true,
                'depends_on_exam_id' => null,
                'mode' => 'standard',
                'is_published' => true,
            ]);
            $this->seedStandardQuestions($quiz, $category);
            $exams[] = $quiz;

            $assignment = Exam::create([
                'course_id' => $course->id,
                'lesson_id' => $lesson?->id, // a per-lesson assignment
                'title' => 'الواجب الأول - '.$course->title,
                'type' => 'assignment',
                'pass_percent' => 50,
                'duration_min' => null,
                'attempts_allowed' => 0, // unlimited
                'question_order' => 'fixed',
                'scoring' => 'last',
                'starts_at' => now()->subWeeks(2),
                'ends_at' => now()->addWeeks(2),
                'result_visibility' => 'after_close',
                'show_answers' => false,
                'depends_on_exam_id' => $quiz->id,
                'mode' => 'standard',
                'is_published' => true,
            ]);
            $this->seedStandardQuestions($assignment, $category);
            $exams[] = $assignment;

            // A retired (soft-deleted) exam to populate exams.deleted_at.
            $old = Exam::create([
                'course_id' => $course->id,
                'lesson_id' => null,
                'title' => 'امتحان قديم (مؤرشف) - '.$course->title,
                'type' => 'exam',
                'pass_percent' => 50,
                'duration_min' => 30,
                'attempts_allowed' => 1,
                'question_order' => 'fixed',
                'scoring' => 'first',
                'starts_at' => now()->subMonths(6),
                'ends_at' => now()->subMonths(4),
                'result_visibility' => 'manual',
                'show_answers' => false,
                'depends_on_exam_id' => null,
                'mode' => 'standard',
                'is_published' => false,
            ]);
            $old->deleted_at = now()->subMonths(3);
            $old->saveQuietly();
        }

        if ($kind === 'bubble') {
            $bubble = Exam::create([
                'course_id' => $course->id,
                'lesson_id' => null,
                'title' => 'امتحان البابل شيت - '.$course->title,
                'type' => 'exam',
                'pass_percent' => 55,
                'duration_min' => 60,
                'attempts_allowed' => 1,
                'question_order' => 'fixed',
                'scoring' => 'best',
                'starts_at' => now()->subWeeks(3),
                'ends_at' => now()->addWeeks(3),
                'result_visibility' => 'after_close',
                'show_answers' => false,
                'depends_on_exam_id' => null,
                'mode' => 'bubble_sheet',
                'is_published' => true,
            ]);
            $this->seedBubbleQuestions($bubble, $category);
            $exams[] = $bubble;
        }

        return $exams;
    }

    private function seedStandardQuestions(Exam $exam, CourseCategory $category): void
    {
        $questions = [
            ['type' => 'mcq', 'body' => 'أيٌّ من التالي وحدةٌ لقياس الطاقة؟', 'options' => ['نيوتن', 'جول', 'واط', 'باسكال'], 'correct' => [1], 'points' => 2],
            ['type' => 'true_false', 'body' => 'سرعة الضوء في الفراغ ثابتة لجميع المراقبين.', 'options' => ['صح', 'خطأ'], 'correct' => [0], 'points' => 1],
            ['type' => 'short', 'body' => 'اذكر تعريف الظاهرة الكهروضوئية في سطرين.', 'options' => null, 'correct' => null, 'points' => 3],
            ['type' => 'essay', 'body' => 'اشرح بالتفصيل نموذج بور للذرة موضّحًا فروضه.', 'options' => null, 'correct' => null, 'points' => 5],
            ['type' => 'file', 'body' => 'ارفع ورقة الحل المصوّرة للمسألة التطبيقية.', 'options' => null, 'correct' => null, 'points' => 4],
        ];

        foreach ($questions as $i => $q) {
            Question::create([
                'exam_id' => $exam->id,
                'category_id' => $category->id,
                'type' => $q['type'],
                'body' => $q['body'],
                'options' => $q['options'],
                'correct' => $q['correct'],
                'points' => $q['points'],
                'book_ref' => ['book' => 'الكتاب المدرسي', 'page' => 40 + $i, 'qno' => $i + 1],
                'sort_order' => $i + 1,
            ]);
        }
    }

    private function seedBubbleQuestions(Exam $exam, CourseCategory $category): void
    {
        for ($i = 0; $i < 6; $i++) {
            Question::create([
                'exam_id' => $exam->id,
                'category_id' => $category->id,
                'type' => 'mcq',
                'body' => 'سؤال بابل شيت رقم '.($i + 1).' (يُصحَّح بالماسح الضوئي).',
                'options' => ['أ', 'ب', 'ج', 'د'],
                'correct' => [$i % 4],
                'points' => 1,
                'book_ref' => ['book' => 'كتاب الامتحان', 'page' => 10 + $i, 'qno' => $i + 1],
                'sort_order' => $i + 1,
            ]);
        }
    }

    // =====================================================================
    // Media extras (variety) + a global callback event
    // =====================================================================

    /**
     * @param  array<int, Course>  $courses
     * @param  array<int, array<int, Lesson>>  $lessonsByCourse
     * @param  array<int, User>  $students
     */
    private function seedMediaExtras(Tenant $tenant, array $courses, array $lessonsByCourse, User $teacher, array $students): void
    {
        // Find the first uploaded video asset in the academy.
        $asset = MediaAsset::where('type', 'hls_video')->orderBy('id')->first();
        if ($asset === null) {
            return;
        }

        // A second version being prepared (state=processing) + a failed one, to
        // populate the version state machine and the `error` column.
        $v2 = MediaVersion::create([
            'media_asset_id' => $asset->id,
            'version' => 2,
            'provider' => 'remote',
            'state' => 'processing',
            'host_video_id' => 'vid_'.Str::lower(Str::random(18)),
            'playback_id' => 'pb_'.Str::lower(Str::random(18)),
            'thumbnail_url' => $asset->thumbnail_url,
            'duration_sec' => $asset->duration_sec,
            'meta' => ['reason' => 'إعادة رفع بجودة أعلى', 'requested_by' => $teacher->id],
            'error' => null,
            'ready_at' => null,
        ]);
        MediaUploadSession::create([
            'media_version_id' => $v2->id,
            'created_by' => $teacher->id,
            'idempotency_key' => 'ups_'.Str::lower(Str::random(40)),
            'host_upload_id' => 'up_'.Str::lower(Str::random(18)),
            'upload_url' => 'https://uploads.media-host.example/'.Str::lower(Str::random(24)),
            'protocol' => 'multipart',
            'size_bytes' => null,
            'max_bytes' => 5368709120,
            'content_type' => 'video/mp4',
            'checksum_sha256' => null,
            'state' => 'uploading',
            'expires_at' => now()->addDay(),
        ]);

        $vFailed = MediaVersion::create([
            'media_asset_id' => $asset->id,
            'version' => 3,
            'provider' => 'remote',
            'state' => 'failed',
            'host_video_id' => 'vid_'.Str::lower(Str::random(18)),
            'playback_id' => null,
            'thumbnail_url' => null,
            'duration_sec' => null,
            'meta' => ['attempt' => 1],
            'error' => 'فشل الترميز: الملف تالف أو غير مدعوم (codec).',
            'ready_at' => null,
        ]);

        // Per-student encrypted HLS renditions (one ready, one failed).
        MediaRendition::create([
            'media_asset_id' => $asset->id,
            'user_id' => $students[0]->id,
            'status' => 'ready',
            'hls_dir' => 'renditions/'.$tenant->id.'/'.$asset->id.'/'.$students[0]->id,
            'enc_key' => Str::random(32),
            'iv' => bin2hex(random_bytes(16)),
            'segment_count' => (int) ceil(($asset->duration_sec ?: 600) / 6),
            'error' => null,
        ]);
        MediaRendition::create([
            'media_asset_id' => $asset->id,
            'user_id' => $students[1]->id,
            'status' => 'failed',
            'hls_dir' => 'renditions/'.$tenant->id.'/'.$asset->id.'/'.$students[1]->id,
            'enc_key' => Str::random(32),
            'iv' => bin2hex(random_bytes(16)),
            'segment_count' => 0,
            'error' => 'فشل توليد النسخة المشفّرة، ستُعاد المحاولة تلقائيًا.',
        ]);

        // A media host callback event (global-ish table; tenant_id nullable).
        MediaCallbackEvent::create([
            'event_id' => 'evt_'.Str::lower(Str::random(24)),
            'tenant_id' => $tenant->id,
            'media_version_id' => $asset->current_version_id,
            'type' => 'video.asset.ready',
            'payload_hash' => hash('sha256', 'ready:'.$asset->id),
            'processed_at' => now()->subMonths(2),
        ]);
    }

    // =====================================================================
    // Bundles
    // =====================================================================

    /**
     * @param  array<int, Course>  $courses
     * @param  array<int, array<int, Unit>>  $unitsByCourse
     * @param  array<int, array<int, Lesson>>  $lessonsByCourse
     * @return array<int, Bundle>
     */
    private function seedBundles(Tenant $tenant, array $courses, array $unitsByCourse, array $lessonsByCourse): array
    {
        $paid = $this->paidCourses($courses);
        if (count($paid) < 2) {
            return [];
        }

        [$firstIdx, $secondIdx] = array_slice(array_keys($paid), 0, 2);

        $bundle = new Bundle([
            'title' => 'بكدج التفوق الشامل',
            'subtitle' => 'كورس كامل + وحدة مختارة + درس مميّز بسعر موفّر',
            'slug' => 'success-mega-bundle',
            'description' => 'باقة موفّرة تجمع أقوى الكورسات مع وحدة ودرس إضافيين، وصلاحية وصول ممتدة لكل المحتوى.',
            'price_minor' => 90000,
            'currency' => 'EGP',
            'access_days' => 365,
            'visibility' => 'visible',
            'publish_at' => now()->subMonth(),
            'is_free' => false,
            'purchase_enabled' => true,
            'cover_url' => 'https://cdn.elameed.app/bundles/success-cover.jpg',
            'thumbnail_url' => 'https://cdn.elameed.app/bundles/success-thumb.jpg',
        ]);
        $bundle->save();

        BundleItem::create(['bundle_id' => $bundle->id, 'item_type' => 'course', 'course_id' => $courses[$firstIdx]->id, 'unit_id' => null, 'lesson_id' => null, 'sort_order' => 1]);
        BundleItem::create(['bundle_id' => $bundle->id, 'item_type' => 'unit', 'course_id' => null, 'unit_id' => $unitsByCourse[$secondIdx][0]->id, 'lesson_id' => null, 'sort_order' => 2]);
        BundleItem::create(['bundle_id' => $bundle->id, 'item_type' => 'lesson', 'course_id' => null, 'unit_id' => null, 'lesson_id' => $lessonsByCourse[$secondIdx][0]->id, 'sort_order' => 3]);

        // A retired (soft-deleted) bundle → populates bundles.deleted_at.
        $old = new Bundle([
            'title' => 'باقة الترم الأول (منتهية)',
            'subtitle' => 'عرض موسمي انتهى',
            'slug' => 'term1-expired-bundle',
            'description' => 'باقة موسمية لم تعد متاحة للشراء.',
            'price_minor' => 70000,
            'currency' => 'EGP',
            'access_days' => 120,
            'visibility' => 'hidden',
            'publish_at' => now()->subMonths(8),
            'is_free' => false,
            'purchase_enabled' => false,
            'cover_url' => 'https://cdn.elameed.app/bundles/term1-cover.jpg',
            'thumbnail_url' => 'https://cdn.elameed.app/bundles/term1-thumb.jpg',
        ]);
        $old->save();
        BundleItem::create(['bundle_id' => $old->id, 'item_type' => 'course', 'course_id' => $courses[$firstIdx]->id, 'unit_id' => null, 'lesson_id' => null, 'sort_order' => 1]);
        $old->deleted_at = now()->subMonths(4);
        $old->saveQuietly();

        return [$bundle];
    }

    // =====================================================================
    // Centres, activation codes, attendance
    // =====================================================================

    /** @return array<int, Center> */
    private function seedCenters(Tenant $tenant, array $config): array
    {
        $centers = [];
        foreach ($config['centers'] as $i => $c) {
            $centers[] = Center::create([
                'name' => $c['name'],
                'address' => $c['address'],
                'phone' => $config['base'].'0030'.$i,
                'is_active' => $c['active'],
            ]);
        }

        return $centers;
    }

    /**
     * @param  array<int, Center>  $centers
     * @param  array<int, User>  $students
     */
    private function seedActivationCodes(Tenant $tenant, array $centers, ?Course $centerCourse, array $students): void
    {
        $batch = 'BATCH-'.now()->format('Ym');

        // Wallet top-up codes: one redeemed, one active, one disabled.
        $walletCodes = [
            ['status' => 'redeemed', 'by' => $students[0], 'at' => now()->subDays(10)],
            ['status' => 'active', 'by' => null, 'at' => null],
            ['status' => 'disabled', 'by' => null, 'at' => null],
        ];
        foreach ($walletCodes as $i => $wc) {
            ActivationCode::create([
                'code' => 'WLT-'.strtoupper(Str::random(8)),
                'type' => 'wallet',
                'amount_minor' => 10000,
                'course_id' => null,
                'center_id' => $centers[0]->id,
                'batch' => $batch,
                'status' => $wc['status'],
                'redeemed_by' => $wc['by']?->id,
                'redeemed_at' => $wc['at'],
                'expires_at' => now()->addMonths(6),
            ]);
        }

        // Course activation codes tied to the centre course.
        if ($centerCourse !== null) {
            ActivationCode::create([
                'code' => 'CRS-'.strtoupper(Str::random(8)),
                'type' => 'course',
                'amount_minor' => null,
                'course_id' => $centerCourse->id,
                'center_id' => $centers[0]->id,
                'batch' => $batch,
                'status' => 'redeemed',
                'redeemed_by' => $students[2]->id,
                'redeemed_at' => now()->subDays(7),
                'expires_at' => now()->addMonths(4),
            ]);
            ActivationCode::create([
                'code' => 'CRS-'.strtoupper(Str::random(8)),
                'type' => 'course',
                'amount_minor' => null,
                'course_id' => $centerCourse->id,
                'center_id' => $centers[0]->id,
                'batch' => $batch,
                'status' => 'active',
                'redeemed_by' => null,
                'redeemed_at' => null,
                'expires_at' => now()->addMonths(4),
            ]);
        }
    }

    /**
     * @param  array<int, Center>  $centers
     * @param  array<int, User>  $students
     */
    private function seedAttendance(Tenant $tenant, array $centers, ?Course $centerCourse, array $students, User $marker): void
    {
        $center = $centers[0];
        $courseId = $centerCourse?->id;
        $seq = 0;

        foreach ([$students[2], $students[3] ?? $students[0]] as $s) {
            // Three sessions across three days: present (online), present (offline), absent.
            $days = [
                ['date' => now()->subDays(9)->toDateString(), 'status' => 'present', 'source' => 'online', 'note' => 'حضور كامل عبر البث المباشر'],
                ['date' => now()->subDays(6)->toDateString(), 'status' => 'present', 'source' => 'offline', 'note' => 'حضور حضوري بالسنتر'],
                ['date' => now()->subDays(3)->toDateString(), 'status' => 'absent', 'source' => 'offline', 'note' => 'غياب بعذر'],
            ];
            foreach ($days as $d) {
                AttendanceRecord::create([
                    'center_id' => $center->id,
                    'user_id' => $s->id,
                    'course_id' => $courseId,
                    'attended_on' => $d['date'],
                    'status' => $d['status'],
                    'marked_by' => $marker->id,
                    'source' => $d['source'],
                    'external_ref' => 'ATT-'.$tenant->id.'-'.(++$seq).'-'.strtoupper(Str::random(5)),
                    'note' => $d['note'],
                ]);
            }
        }
    }

    // =====================================================================
    // Commerce: orders, items, payments, invoices, enrollments, ledger
    // =====================================================================

    /**
     * @param  array<int, User>  $students
     * @param  array<int, Course>  $courses
     * @param  array<int, Bundle>  $bundles
     * @param  array<int, array<int, Unit>>  $unitsByCourse
     * @param  array<int, array<int, Lesson>>  $lessonsByCourse
     */
    private function seedCommerce(Tenant $tenant, array $config, User $teacher, array $students, array $courses, array $bundles, array $unitsByCourse, array $lessonsByCourse): void
    {
        $paid = $this->paidCourses($courses);
        $paidIdx = array_keys($paid);
        $courseA = $courses[$paidIdx[0]];
        $courseB = $courses[$paidIdx[1]];
        $freeCourse = $this->firstFreeCourse($courses);
        $centerCourse = $this->firstCenterCourse($courses);

        // Everyone (active students) is enrolled in the free course.
        foreach ($students as $s) {
            $this->createEnrollment($tenant, $s, 'manual', 'active', $freeCourse, null, null, null, now()->subMonths(1), null);
        }

        // --- Student 0: card purchase of course A, then wallet top-up + wallet buy of course B
        $orderA = $this->createOrder($tenant, $students[0], 'paid', [
            ['item_type' => 'course', 'item_id' => $courseA->id, 'price_minor' => $courseA->price_minor, 'title' => $courseA->title],
        ], 'paymob', 'paid', now()->subDays(20), 1);
        $this->createEnrollment($tenant, $students[0], 'purchase', 'active', $courseA, null, null, null, now()->subDays(20), $courseA->access_days ? now()->subDays(20)->addDays($courseA->access_days) : null);
        $this->postSale($tenant, $orderA, $courseA->price_minor, null);

        // Wallet top-up (Fawry) → credits student wallet via ledger.
        $topup = $this->createOrder($tenant, $students[0], 'paid', [
            ['item_type' => 'wallet_topup', 'item_id' => null, 'price_minor' => 100000, 'title' => 'شحن محفظة'],
        ], 'fawry', 'paid', now()->subDays(15), null);
        $walletA = $this->walletOf($tenant, $students[0]);
        $this->post($tenant->id, 'topup:'.$topup->id, [
            ['account' => LedgerEntry::GATEWAY_CLEARING, 'direction' => LedgerEntry::DEBIT, 'amount_minor' => 100000, 'wallet_id' => null],
            ['account' => LedgerEntry::STUDENT_WALLET, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => 100000, 'wallet_id' => $walletA->id],
        ], 'order', $topup->id, now()->subDays(15));

        // Buy course B from wallet.
        $orderB = $this->createOrder($tenant, $students[0], 'paid', [
            ['item_type' => 'course', 'item_id' => $courseB->id, 'price_minor' => $courseB->price_minor, 'title' => $courseB->title],
        ], 'wallet', 'paid', now()->subDays(14), null);
        $this->createEnrollment($tenant, $students[0], 'wallet', 'active', $courseB, null, null, null, now()->subDays(14), $courseB->access_days ? now()->subDays(14)->addDays($courseB->access_days) : null);
        $this->post($tenant->id, 'order:'.$orderB->id, $this->saleLegs($walletA->id, $courseB->price_minor), 'order', $orderB->id, now()->subDays(14));

        // A refunded duplicate order for student 0 (populates 'refunded').
        $this->createOrder($tenant, $students[0], 'refunded', [
            ['item_type' => 'course', 'item_id' => $courseA->id, 'price_minor' => $courseA->price_minor, 'title' => $courseA->title.' (مكرر - مُسترجع)'],
        ], 'paymob', 'paid', now()->subDays(19), null);

        // --- Student 1: buys the bundle (card) → grants for each bundle item
        if (! empty($bundles)) {
            $bundle = $bundles[0];
            $orderBundle = $this->createOrder($tenant, $students[1], 'paid', [
                ['item_type' => 'bundle', 'item_id' => $bundle->id, 'price_minor' => $bundle->price_minor, 'title' => $bundle->title],
            ], 'paymob', 'paid', now()->subDays(12), null);
            $bundle->loadMissing('items');
            $exp = $bundle->access_days ? now()->subDays(12)->addDays($bundle->access_days) : null;
            foreach ($bundle->items as $item) {
                $this->createEnrollment(
                    $tenant, $students[1], 'purchase', 'active',
                    $item->item_type === 'course' ? $courses[$this->courseIndexById($courses, (int) $item->course_id)] : null,
                    $item->item_type === 'unit' ? (new Unit)->newQuery()->find($item->unit_id) : null,
                    $item->item_type === 'lesson' ? (new Lesson)->newQuery()->find($item->lesson_id) : null,
                    $bundle->id, now()->subDays(12), $exp,
                );
            }
            $this->postSale($tenant, $orderBundle, $bundle->price_minor, null);
        }

        // --- Student 2: code redemption (centre course) + centre enrollment
        if ($centerCourse !== null) {
            $this->createEnrollment($tenant, $students[2], 'code', 'active', $centerCourse, null, null, null, now()->subDays(7), $centerCourse->access_days ? now()->subDays(7)->addDays($centerCourse->access_days) : null);
            $this->createEnrollment($tenant, $students[2], 'center', 'active', $centerCourse, null, null, null, now()->subDays(7), now()->addDays(90));
        }

        // --- Student 3: manual grant of course A + an EXPIRED enrollment on course B
        $this->createEnrollment($tenant, $students[3], 'manual', 'active', $courseA, null, null, null, now()->subDays(30), now()->addDays(60));
        $this->createEnrollment($tenant, $students[3], 'purchase', 'expired', $courseB, null, null, null, now()->subDays(400), now()->subDays(35));

        // --- Student 4: a pending (abandoned) order + a failed order + a cancelled enrollment
        $this->createOrder($tenant, $students[4], 'pending', [
            ['item_type' => 'course', 'item_id' => $courseA->id, 'price_minor' => $courseA->price_minor, 'title' => $courseA->title],
        ], 'paymob', 'pending', now()->subDays(2), null);
        $this->createOrder($tenant, $students[4], 'failed', [
            ['item_type' => 'course', 'item_id' => $courseB->id, 'price_minor' => $courseB->price_minor, 'title' => $courseB->title],
        ], 'fawry', 'failed', now()->subDays(1), null);
        $this->createEnrollment($tenant, $students[4], 'purchase', 'cancelled', $courseA, null, null, null, now()->subDays(40), null);
    }

    /**
     * Create an order + its items + a payment (+ an invoice when paid).
     *
     * @param  array<int, array{item_type: string, item_id: int|null, price_minor: int, title: string}>  $items
     */
    private function createOrder(Tenant $tenant, User $user, string $status, array $items, string $gateway, string $paymentStatus, Carbon $when, ?int $couponId): Order
    {
        $total = array_sum(array_column($items, 'price_minor'));

        $order = new Order([
            'user_id' => $user->id,
            'total_minor' => $total,
            'currency' => 'EGP',
            'coupon_id' => $couponId,
            'status' => $status,
        ]);
        $order->created_at = $when;
        $order->updated_at = $when;
        $order->save();

        foreach ($items as $item) {
            OrderItem::create([
                'order_id' => $order->id,
                'item_type' => $item['item_type'],
                'item_id' => $item['item_id'],
                'price_minor' => $item['price_minor'],
                'title' => $item['title'],
            ]);
        }

        $payment = new Payment([
            'order_id' => $order->id,
            'gateway' => $gateway,
            'gateway_txn_id' => $gateway.'_'.strtoupper(Str::random(16)),
            'amount_minor' => $total,
            'status' => $paymentStatus,
            'reference_number' => $gateway === 'fawry' ? (string) random_int(100000000, 999999999) : 'REF-'.strtoupper(Str::random(10)),
            'raw_payload' => ['gateway' => $gateway, 'success' => $paymentStatus === 'paid', 'order_uuid' => $order->uuid, 'captured_at' => $when->toIso8601String()],
            'processed_at' => $paymentStatus === 'pending' ? null : $when->copy()->addMinutes(3),
        ]);
        $payment->created_at = $when;
        $payment->updated_at = $when;
        $payment->save();

        if ($status === 'paid' || $status === 'refunded') {
            $invoice = new Invoice([
                'order_id' => $order->id,
                'number' => ++$this->invoiceSeq,
                'pdf_url' => 'https://cdn.elameed.app/invoices/'.$order->uuid.'.pdf',
                'eta_receipt_uuid' => (string) Str::uuid(),
                'issued_at' => $when->copy()->addMinutes(5),
            ]);
            $invoice->created_at = $when;
            $invoice->updated_at = $when;
            $invoice->save();
        }

        return $order;
    }

    /** Post the ledger legs for a card/gateway sale (money in → split teacher/platform). */
    private function postSale(Tenant $tenant, Order $order, int $amount, ?int $walletId): void
    {
        $commission = (int) round($amount * self::COMMISSION);
        $this->post($tenant->id, 'order:'.$order->id, [
            ['account' => LedgerEntry::GATEWAY_CLEARING, 'direction' => LedgerEntry::DEBIT, 'amount_minor' => $amount, 'wallet_id' => null],
            ['account' => LedgerEntry::TEACHER_EARNINGS, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => $amount - $commission, 'wallet_id' => null],
            ['account' => LedgerEntry::PLATFORM_COMMISSION, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => $commission, 'wallet_id' => null],
        ], 'order', $order->id, $order->created_at);
    }

    /** Ledger legs for a wallet-funded sale (debit wallet → split teacher/platform). */
    private function saleLegs(int $walletId, int $amount): array
    {
        $commission = (int) round($amount * self::COMMISSION);

        return [
            ['account' => LedgerEntry::STUDENT_WALLET, 'direction' => LedgerEntry::DEBIT, 'amount_minor' => $amount, 'wallet_id' => $walletId],
            ['account' => LedgerEntry::TEACHER_EARNINGS, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => $amount - $commission, 'wallet_id' => null],
            ['account' => LedgerEntry::PLATFORM_COMMISSION, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => $commission, 'wallet_id' => null],
        ];
    }

    private function post(int $tenantId, string $opKey, array $legs, string $refType, int $refId, Carbon $when): void
    {
        foreach ($legs as $i => $leg) {
            $entry = new LedgerEntry([
                'wallet_id' => $leg['wallet_id'] ?? null,
                'account' => $leg['account'],
                'direction' => $leg['direction'],
                'amount_minor' => $leg['amount_minor'],
                'ref_type' => $refType,
                'ref_id' => $refId,
                'idempotency_key' => $opKey.':'.$i.':'.$leg['account'].':'.$leg['direction'],
            ]);
            $entry->tenant_id = $tenantId;
            $entry->created_at = $when;
            $entry->save();
        }
    }

    private function createEnrollment(Tenant $tenant, User $user, string $source, string $status, ?Course $course, ?Unit $unit, ?Lesson $lesson, ?int $bundleId, ?Carbon $startsAt, ?Carbon $expiresAt): Enrollment
    {
        return Enrollment::create([
            'user_id' => $user->id,
            'course_id' => $course?->id,
            'unit_id' => $unit?->id,
            'lesson_id' => $lesson?->id,
            'bundle_id' => $bundleId,
            'source' => $source,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'status' => $status,
        ]);
    }

    // =====================================================================
    // Engagement: reviews, favorites, progress, points, badges, playback
    // =====================================================================

    /**
     * @param  array<int, Course>  $courses
     * @param  array<int, array<int, Lesson>>  $lessonsByCourse
     * @param  array<int, array<int, Exam>>  $examsByCourse
     * @param  array<int, User>  $students
     */
    private function seedEngagement(Tenant $tenant, array $courses, array $lessonsByCourse, array $examsByCourse, array $students, User $teacher): void
    {
        $paid = $this->paidCourses($courses);
        $paidIdx = array_keys($paid);
        $courseAIdx = $paidIdx[0];
        $courseA = $courses[$courseAIdx];

        // --- Badges (thresholds) + awards ---------------------------------
        $badgeSpecs = [
            ['name' => 'المتفوق', 'description' => 'حصل على أكثر من ١٠٠ نقطة', 'icon' => '🏅', 'threshold' => 100],
            ['name' => 'المثابر', 'description' => 'أكمل ٥ دروس متتالية', 'icon' => '🔥', 'threshold' => 300],
            ['name' => 'نجم الشهر', 'description' => 'الأول على المستوى الشهري', 'icon' => '⭐', 'threshold' => 1000],
        ];
        $badges = [];
        foreach ($badgeSpecs as $b) {
            $badges[] = Badge::create([
                'name' => $b['name'],
                'description' => $b['description'],
                'icon' => $b['icon'],
                'points_threshold' => $b['threshold'],
            ]);
        }

        // --- Reviews: student-authored (visible + one hidden) + guest/imported
        $comments = [
            'شرح ممتاز وأسلوب مبسّط، استفدت جدًا وارتفعت درجاتي.',
            'أفضل كورس شرح لهذه المادة، التطبيقات والأمثلة رائعة.',
            'المتابعة المستمرة وحل الأسئلة بعد كل درس فرق كبير معايا.',
            'المحتوى منظّم والفيديوهات واضحة، أنصح به بشدة.',
        ];
        foreach (array_slice($students, 0, 4) as $i => $s) {
            Review::create([
                'course_id' => $courseA->id,
                'user_id' => $s->id,
                'author_name' => null,
                'rating' => 5 - ($i % 2),
                'comment' => $comments[$i],
                'is_visible' => $i !== 3, // one hidden review
            ]);
        }
        // Guest / teacher-imported testimonials (user_id null, author_name set).
        Review::create(['course_id' => $courseA->id, 'user_id' => null, 'author_name' => 'ولي أمر - أ. محمد', 'rating' => 5, 'comment' => 'ابني اتحسّن مستواه كتير بعد الكورس، شكرًا لحضراتكم.', 'is_visible' => true]);
        Review::create(['course_id' => $courseA->id, 'user_id' => null, 'author_name' => 'طالبة سابقة', 'rating' => 4, 'comment' => 'كورس مفيد جدًا وساعدني في الثانوية العامة.', 'is_visible' => true]);

        // --- Favorites -----------------------------------------------------
        foreach (array_slice($students, 0, 3) as $s) {
            Favorite::create(['user_id' => $s->id, 'course_id' => $courseA->id]);
        }
        if (isset($courses[$paidIdx[1]])) {
            Favorite::create(['user_id' => $students[0]->id, 'course_id' => $courses[$paidIdx[1]]->id]);
        }

        // --- Lesson progress + points --------------------------------------
        $lessons = $lessonsByCourse[$courseAIdx];
        $active = array_slice($students, 0, 3);
        foreach ($active as $si => $s) {
            $enrollment = Enrollment::where('user_id', $s->id)->where('course_id', $courseA->id)->first();
            foreach ($lessons as $li => $lesson) {
                $completed = $li < (3 - $si); // earlier students completed more
                LessonProgress::create([
                    'enrollment_id' => $enrollment?->id,
                    'lesson_id' => $lesson->id,
                    'user_id' => $s->id,
                    'watch_percent' => $completed ? 100 : (($li === (3 - $si)) ? 45 : 0),
                    'watch_seconds' => $completed ? $lesson->duration_sec : (int) round(($lesson->duration_sec ?? 0) * 0.45),
                    'sessions_count' => $completed ? 2 : 1,
                    'last_position_sec' => $completed ? $lesson->duration_sec : (int) round(($lesson->duration_sec ?? 0) * 0.45),
                    'completed_at' => $completed ? now()->subDays(10 - $li) : null,
                ]);

                if ($completed) {
                    PointsEntry::create([
                        'user_id' => $s->id,
                        'points' => 10,
                        'reason' => 'lesson.completed',
                        'ref_type' => 'lesson',
                        'ref_id' => $lesson->id,
                        'idempotency_key' => 'pts:lesson:'.$lesson->id.':'.$s->id,
                    ]);
                }
            }
        }

        // A manual bonus and a correction (negative) to exercise signed points.
        PointsEntry::create(['user_id' => $students[0]->id, 'points' => 100, 'reason' => 'manual', 'ref_type' => 'bonus', 'ref_id' => null, 'idempotency_key' => 'pts:manual:'.$students[0]->id]);
        PointsEntry::create(['user_id' => $students[0]->id, 'points' => -20, 'reason' => 'manual', 'ref_type' => 'correction', 'ref_id' => null, 'idempotency_key' => 'pts:correction:'.$students[0]->id]);

        // Award badges to the top two students.
        StudentBadge::create(['user_id' => $students[0]->id, 'badge_id' => $badges[0]->id, 'awarded_at' => now()->subDays(9)]);
        StudentBadge::create(['user_id' => $students[0]->id, 'badge_id' => $badges[1]->id, 'awarded_at' => now()->subDays(4)]);
        StudentBadge::create(['user_id' => $students[1]->id, 'badge_id' => $badges[0]->id, 'awarded_at' => now()->subDays(8)]);

        // --- Exam attempts (graded / submitted / in-progress) --------------
        if (! empty($examsByCourse[$courseAIdx])) {
            $exam = $examsByCourse[$courseAIdx][0];
            $exam->loadMissing('questions');
            $maxScore = (int) $exam->questions->sum('points');

            // Student 0: graded pass.
            $this->createAttempt($exam, $students[0], 1, 'graded', $maxScore, (int) round($maxScore * 0.85), false, now()->subDays(8));
            // Student 1: submitted, needs manual grade (essay pending).
            $this->createAttempt($exam, $students[1], 1, 'submitted', $maxScore, null, true, now()->subDays(6));
            // Student 2: in progress.
            $this->createAttempt($exam, $students[2], 1, 'in_progress', $maxScore, null, false, now()->subDays(1));

            // Points for passing.
            PointsEntry::create(['user_id' => $students[0]->id, 'points' => 50, 'reason' => 'exam.passed', 'ref_type' => 'exam', 'ref_id' => $exam->id, 'idempotency_key' => 'pts:exam:'.$exam->id.':'.$students[0]->id]);
        }

        // --- Playback sessions (active + revoked + preview) ----------------
        $this->seedPlaybackSessions($tenant, $courseA, $lessons, $students, $teacher);
    }

    private function createAttempt(Exam $exam, User $user, int $number, string $status, int $maxScore, ?int $score, bool $needsManual, Carbon $startedAt): void
    {
        $answers = [];
        foreach ($exam->questions as $q) {
            $answers[(string) $q->id] = match ($q->type->value) {
                'mcq', 'true_false' => ['answer' => is_array($q->correct) ? ($q->correct[0] ?? 0) : 0, 'awarded' => $status === 'graded' ? $q->points : 0, 'is_correct' => $status === 'graded'],
                'short' => ['answer' => 'إجابة مختصرة نموذجية من الطالب.', 'awarded' => $status === 'graded' ? $q->points : 0, 'is_correct' => $status === 'graded'],
                'essay' => ['answer' => 'مقال تفصيلي بانتظار التصحيح اليدوي.', 'awarded' => 0, 'is_correct' => null],
                'file' => ['answer' => 'uploads/answers/'.Str::lower(Str::random(12)).'.jpg', 'awarded' => $status === 'graded' ? $q->points : 0, 'is_correct' => $status === 'graded'],
                default => ['answer' => null, 'awarded' => 0, 'is_correct' => false],
            };
        }

        ExamAttempt::create([
            'exam_id' => $exam->id,
            'user_id' => $user->id,
            'attempt_number' => $number,
            'started_at' => $startedAt,
            'submitted_at' => $status === 'in_progress' ? null : $startedAt->copy()->addMinutes(35),
            'score' => $score,
            'max_score' => $maxScore,
            'status' => $status,
            'answers' => $answers,
            'needs_manual_grade' => $needsManual,
        ]);
    }

    /**
     * @param  array<int, Lesson>  $lessons
     * @param  array<int, User>  $students
     */
    private function seedPlaybackSessions(Tenant $tenant, Course $course, array $lessons, array $students, User $teacher): void
    {
        $lesson = $lessons[1] ?? $lessons[0];
        $asset = $lesson->video_asset_id;
        $version = $asset ? MediaAsset::find($asset)?->current_version_id : null;

        // Active student session.
        PlaybackSession::create([
            'user_id' => $students[0]->id,
            'lesson_id' => $lesson->id,
            'media_asset_id' => $asset,
            'media_version_id' => $version,
            'scope' => 'student',
            'token_hash' => hash('sha256', 'pb:'.$students[0]->id.':'.$lesson->id),
            'device_fingerprint' => 'fp_'.Str::lower(Str::random(20)),
            'ip' => '156.199.44.'.random_int(2, 250),
            'issued_at' => now()->subMinutes(30),
            'expires_at' => now()->addHours(4),
            'revoked_at' => null,
        ]);

        // Revoked session (device-limit hit).
        PlaybackSession::create([
            'user_id' => $students[1]->id,
            'lesson_id' => $lesson->id,
            'media_asset_id' => $asset,
            'media_version_id' => $version,
            'scope' => 'student',
            'token_hash' => hash('sha256', 'pb:'.$students[1]->id.':'.$lesson->id),
            'device_fingerprint' => 'fp_'.Str::lower(Str::random(20)),
            'ip' => '197.44.12.'.random_int(2, 250),
            'issued_at' => now()->subDays(1)->subHours(2),
            'expires_at' => now()->subDays(1)->addHours(2),
            'revoked_at' => now()->subDays(1),
        ]);

        // Teacher preview session.
        PlaybackSession::create([
            'user_id' => $teacher->id,
            'lesson_id' => $lesson->id,
            'media_asset_id' => $asset,
            'media_version_id' => $version,
            'scope' => 'preview',
            'token_hash' => hash('sha256', 'pb:preview:'.$teacher->id.':'.$lesson->id),
            'device_fingerprint' => 'fp_'.Str::lower(Str::random(20)),
            'ip' => '156.199.9.'.random_int(2, 250),
            'issued_at' => now()->subHours(6),
            'expires_at' => now()->addHours(2),
            'revoked_at' => null,
        ]);
    }

    // =====================================================================
    // Notifications + per-tenant auth trail
    // =====================================================================

    /**
     * @param  array<int, User>  $students
     * @param  array<int, Course>  $courses
     */
    private function seedNotifications(Tenant $tenant, array $students, User $teacher, array $courses): void
    {
        $courseA = $this->paidCourses($courses)[array_key_first($this->paidCourses($courses))];

        $specs = [
            ['user' => $students[0], 'channel' => 'in_app', 'type' => 'purchase.completed', 'status' => 'sent', 'read' => true, 'payload' => ['course' => $courseA->title, 'order' => 'مدفوع']],
            ['user' => $students[0], 'channel' => 'sms', 'type' => 'wallet.topup', 'status' => 'sent', 'read' => false, 'payload' => ['amount' => '١٠٠٠ ج.م', 'balance' => 'محدّث']],
            ['user' => $students[1], 'channel' => 'whatsapp', 'type' => 'exam.graded', 'status' => 'sent', 'read' => false, 'payload' => ['exam' => 'امتحان الوحدة الأولى', 'result' => 'بانتظار التصحيح']],
            ['user' => $students[2], 'channel' => 'email', 'type' => 'lesson.published', 'status' => 'pending', 'read' => false, 'payload' => ['lesson' => 'درس جديد متاح']],
            ['user' => $teacher, 'channel' => 'in_app', 'type' => 'payout.ready', 'status' => 'failed', 'read' => false, 'payload' => ['reason' => 'فشل الإرسال، إعادة المحاولة']],
        ];

        foreach ($specs as $i => $s) {
            Notification::create([
                'user_id' => $s['user']->id,
                'channel' => $s['channel'],
                'type' => $s['type'],
                'template_id' => 1000 + $i,
                'payload' => $s['payload'],
                'status' => $s['status'],
                'sent_at' => $s['status'] === 'pending' ? null : now()->subDays($i + 1),
                'read_at' => $s['read'] ? now()->subDays($i) : null,
            ]);
        }
    }

    /** @param  array<int, User>  $students */
    private function seedTenantAuthTrail(Tenant $tenant, User $teacher, array $students, string $base): void
    {
        // Sessions + an API token for the teacher.
        $this->makeSession($teacher, '156.199.44.10', now()->subMinutes(12));
        $this->makeAccessToken($teacher, $tenant->slug.'-mobile', ['courses:read', 'students:read'], now()->subHours(5), now()->addMonths(6));

        // Password-reset token for one student.
        DB::table('password_reset_tokens')->insert([
            'email' => $students[0]->email,
            'token' => Str::random(64),
            'created_at' => now()->subMinutes(20),
        ]);

        // Login attempts: teacher success, student success, one failed guess.
        $this->makeLoginAttempt($teacher, $tenant->id, $teacher->phone, '156.199.44.10', true, now()->subMinutes(12));
        $this->makeLoginAttempt($students[0], $tenant->id, $students[0]->phone, '156.199.60.22', true, now()->subHours(3));
        $this->makeLoginAttempt(null, $tenant->id, $base.'00999', '102.44.8.9', false, now()->subHours(5));

        // OTPs: student register (consumed), student login (pending).
        $this->makeOtp($students[0]->phone, 'sms', 'register', 1, now()->subMonths(5)->addMinutes(5), now()->subMonths(5)->addMinutes(2));
        $this->makeOtp($students[1]->phone, 'sms', 'login', 0, now()->addMinutes(8), null);

        // Tenant-scoped audit entries.
        $this->makeAudit($tenant->id, $teacher, 'course.published', Tenant::class, $tenant->id, ['slug' => $tenant->slug], '156.199.44.10', now()->subMonths(2));
        $this->makeAudit($tenant->id, $students[0], 'order.paid', User::class, $students[0]->id, ['amount_minor' => 45000], '156.199.60.22', now()->subDays(20));
    }

    // =====================================================================
    // Landing content (LandingSchema-shaped, fully authored ar + en)
    // =====================================================================

    private function buildLanding(array $config): array
    {
        $sections = LandingSchema::defaults('ar');
        $subject = $config['subject'];
        $teacher = $config['teacher'];

        $ar = [
            'hero' => [
                'eyebrow' => 'منصة تعليمية متخصصة',
                'title_html' => 'تفوّق في <strong>'.$subject.'</strong> مع '.$teacher['name'],
                'description' => 'شرح مبسّط، بنك أسئلة ضخم، ومتابعة مستمرة حتى تحقيق الدرجة النهائية.',
                'note' => 'انضم الآن لآلاف الطلاب الناجحين.',
                'primary_cta' => ['label' => 'ابدأ الآن'],
                'secondary_cta' => ['label' => 'تصفّح الكورسات'],
                'teacher' => [
                    'name' => $teacher['name'],
                    'role' => $teacher['role'],
                    'image_url' => 'https://cdn.elameed.app/'.$config['slug'].'/teacher.jpg',
                    'card_stats' => [
                        ['value' => '12', 'label' => 'سنة خبرة'],
                        ['value' => '+8000', 'label' => 'طالب'],
                        ['value' => '4.9', 'label' => 'التقييم'],
                    ],
                ],
                'chips' => [
                    ['text' => 'ثانوية عامة', 'type' => 'green'],
                    ['text' => 'حصص مباشرة', 'type' => 'plain'],
                    ['text' => 'مراجعات نهائية', 'type' => 'red'],
                ],
            ],
            'stats' => ['items' => [
                ['value' => '+8000', 'label' => 'طالب مشترك'],
                ['value' => '120', 'label' => 'ساعة شرح'],
                ['value' => '95%', 'label' => 'نسبة نجاح'],
                ['value' => '4.9/5', 'label' => 'رضا الطلاب'],
            ]],
            'features' => ['title' => 'لماذا نحن؟', 'subtitle' => 'كل ما تحتاجه للتفوق في مكان واحد', 'items' => [
                ['icon' => '🎥', 'title' => 'فيديوهات محمية', 'desc' => 'مشاهدة آمنة بعلامة مائية باسم الطالب.'],
                ['icon' => '📝', 'title' => 'بنك أسئلة', 'desc' => 'امتحانات وواجبات بعد كل درس مع تصحيح فوري.'],
                ['icon' => '📊', 'title' => 'متابعة التقدّم', 'desc' => 'تقارير أداء لك ولولي أمرك أولًا بأول.'],
                ['icon' => '💬', 'title' => 'دعم مستمر', 'desc' => 'إجابة على أسئلتك من فريق المدرّس.'],
            ]],
            'about' => [
                'badge' => 'من نحن',
                'title' => 'رحلة نجاح تبدأ هنا',
                'body' => 'نقدّم منظومة تعليمية متكاملة في '.$subject.' تجمع بين الشرح المتميّز والتقنية الحديثة لضمان أفضل النتائج.',
                'image_url' => 'https://cdn.elameed.app/'.$config['slug'].'/about.jpg',
                'points' => ['شرح متدرّج ومنظّم', 'اختبارات دورية', 'متابعة أولياء الأمور', 'أسعار مناسبة'],
            ],
            'courses' => ['title' => 'أحدث الكورسات', 'subtitle' => 'اختر ما يناسب صفّك الدراسي'],
            'how' => ['title' => 'كيف تبدأ؟', 'subtitle' => 'ثلاث خطوات فقط', 'items' => [
                ['n' => 1, 'title' => 'سجّل حسابك', 'desc' => 'أنشئ حسابًا برقم موبايلك في دقيقة.'],
                ['n' => 2, 'title' => 'اشترك في كورس', 'desc' => 'ادفع أونلاين أو بكود التفعيل.'],
                ['n' => 3, 'title' => 'ابدأ التعلّم', 'desc' => 'شاهد الدروس وحلّ الامتحانات.'],
            ]],
            'testimonials' => ['title' => 'آراء الطلاب', 'subtitle' => 'قصص نجاح حقيقية'],
            'packages' => ['title' => 'باقات الاشتراك', 'subtitle' => 'اختر الباقة المناسبة', 'items' => [
                ['name' => 'الشهرية', 'price' => '150 ج.م', 'period' => 'شهريًا', 'features' => ['كل الكورسات', 'امتحانات', 'دعم']],
                ['name' => 'الترمية', 'price' => '400 ج.م', 'period' => 'كل ترم', 'features' => ['خصم 20%', 'مراجعات', 'أولوية دعم']],
            ]],
            'cta' => ['title' => 'جاهز تبدأ رحلة التفوق؟', 'subtitle' => 'اشترك الآن واحصل على أول درس مجانًا.', 'cta' => ['label' => 'اشترك الآن']],
            'contact' => ['title' => 'تواصل معنا', 'subtitle' => 'فريقنا في خدمتك طوال الأسبوع'],
        ];

        $en = [
            'hero' => [
                'eyebrow' => 'Specialized learning platform',
                'title_html' => 'Excel in <strong>'.$subject.'</strong> with '.$teacher['name'],
                'description' => 'Clear explanations, a huge question bank, and continuous follow-up until you hit full marks.',
                'note' => 'Join thousands of successful students now.',
                'primary_cta' => ['label' => 'Get started'],
                'secondary_cta' => ['label' => 'Browse courses'],
                'teacher' => [
                    'name' => $teacher['name'],
                    'role' => $teacher['role'],
                    'image_url' => 'https://cdn.elameed.app/'.$config['slug'].'/teacher.jpg',
                    'card_stats' => [
                        ['value' => '12', 'label' => 'Years'],
                        ['value' => '8000+', 'label' => 'Students'],
                        ['value' => '4.9', 'label' => 'Rating'],
                    ],
                ],
                'chips' => [
                    ['text' => 'High school', 'type' => 'green'],
                    ['text' => 'Live classes', 'type' => 'plain'],
                    ['text' => 'Final reviews', 'type' => 'red'],
                ],
            ],
            'stats' => ['items' => [
                ['value' => '8000+', 'label' => 'Students'],
                ['value' => '120', 'label' => 'Hours'],
                ['value' => '95%', 'label' => 'Pass rate'],
                ['value' => '4.9/5', 'label' => 'Satisfaction'],
            ]],
            'features' => ['title' => 'Why us?', 'subtitle' => 'Everything you need to excel', 'items' => [
                ['icon' => '🎥', 'title' => 'Protected videos', 'desc' => 'Secure playback watermarked per student.'],
                ['icon' => '📝', 'title' => 'Question bank', 'desc' => 'Quizzes after every lesson with instant grading.'],
                ['icon' => '📊', 'title' => 'Progress tracking', 'desc' => 'Performance reports for you and your guardian.'],
                ['icon' => '💬', 'title' => 'Ongoing support', 'desc' => 'Get your questions answered by the team.'],
            ]],
            'about' => [
                'badge' => 'About us',
                'title' => 'A success journey starts here',
                'body' => 'An integrated learning system for '.$subject.' combining great teaching with modern technology.',
                'image_url' => 'https://cdn.elameed.app/'.$config['slug'].'/about.jpg',
                'points' => ['Structured lessons', 'Regular tests', 'Guardian follow-up', 'Affordable pricing'],
            ],
            'courses' => ['title' => 'Latest courses', 'subtitle' => 'Pick what fits your grade'],
            'how' => ['title' => 'How to start?', 'subtitle' => 'Just three steps', 'items' => [
                ['n' => 1, 'title' => 'Sign up', 'desc' => 'Create an account with your mobile in a minute.'],
                ['n' => 2, 'title' => 'Subscribe', 'desc' => 'Pay online or with an activation code.'],
                ['n' => 3, 'title' => 'Start learning', 'desc' => 'Watch lessons and take exams.'],
            ]],
            'testimonials' => ['title' => 'Student reviews', 'subtitle' => 'Real success stories'],
            'packages' => ['title' => 'Subscription plans', 'subtitle' => 'Choose the right plan', 'items' => [
                ['name' => 'Monthly', 'price' => 'EGP 150', 'period' => '/mo', 'features' => ['All courses', 'Exams', 'Support']],
                ['name' => 'Termly', 'price' => 'EGP 400', 'period' => '/term', 'features' => ['20% off', 'Reviews', 'Priority support']],
            ]],
            'cta' => ['title' => 'Ready to start?', 'subtitle' => 'Subscribe now and get your first lesson free.', 'cta' => ['label' => 'Subscribe now']],
            'contact' => ['title' => 'Contact us', 'subtitle' => 'Our team is here all week'],
        ];

        foreach ($sections as &$section) {
            $key = $section['key'];
            if (isset($ar[$key])) {
                $section['content']['ar'] = $ar[$key];
            }
            if (isset($en[$key])) {
                $section['content']['en'] = $en[$key];
            }
            if ($key === 'packages') {
                $section['visible'] = true; // show the (seed-managed) pricing section
            }
        }
        unset($section);

        return $sections;
    }

    // =====================================================================
    // Small helpers
    // =====================================================================

    private function makeUser(array $attrs): User
    {
        $user = new User;
        $user->forceFill(array_merge([
            'email_verified_at' => now()->subMonths(5),
            'phone_verified_at' => now()->subMonths(5),
            'password' => 'password', // 'hashed' cast hashes on set
            'locale' => 'ar',
            'is_platform_admin' => false,
            'remember_token' => Str::random(10),
        ], $attrs));
        $user->save();

        return $user;
    }

    private function addMembership(Tenant $tenant, User $user, string $role, string $status, Carbon $joinedAt): TenantUser
    {
        return TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => $status,
            'joined_at' => $joinedAt,
        ]);
    }

    private function makeSession(User $user, string $ip, Carbon $at): void
    {
        DB::table('sessions')->insert([
            'id' => Str::random(40),
            'user_id' => $user->id,
            'ip_address' => $ip,
            'user_agent' => self::UA,
            'payload' => base64_encode(serialize([
                '_token' => Str::random(40),
                '_previous' => ['url' => 'https://elameed.app'],
                '_flash' => ['old' => [], 'new' => []],
                'login_web' => $user->id,
            ])),
            'last_activity' => $at->timestamp,
        ]);
    }

    private function makeAccessToken(User $user, string $name, array $abilities, ?Carbon $lastUsed, ?Carbon $expires): void
    {
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => $name,
            'token' => hash('sha256', Str::random(40)),
            'abilities' => json_encode($abilities),
            'last_used_at' => $lastUsed,
            'expires_at' => $expires,
            'created_at' => now()->subDays(20),
            'updated_at' => $lastUsed ?? now()->subDays(20),
        ]);
    }

    private function makeLoginAttempt(?User $user, ?int $tenantId, string $identifier, string $ip, bool $success, Carbon $at): void
    {
        $attempt = new LoginAttempt;
        $attempt->forceFill([
            'user_id' => $user?->id,
            'tenant_id' => $tenantId,
            'identifier' => $identifier,
            'ip' => $ip,
            'user_agent' => self::UA,
            'success' => $success,
            'created_at' => $at,
        ]);
        $attempt->save();
    }

    private function makeOtp(string $identifier, string $channel, string $purpose, int $attempts, Carbon $expiresAt, ?Carbon $consumedAt): void
    {
        OtpCode::create([
            'identifier' => $identifier,
            'channel' => $channel,
            'purpose' => $purpose,
            'code_hash' => Hash::make('123456'),
            'attempts' => $attempts,
            'expires_at' => $expiresAt,
            'consumed_at' => $consumedAt,
        ]);
    }

    private function makeAudit(?int $tenantId, User $actor, string $action, string $subjectType, ?int $subjectId, array $meta, string $ip, Carbon $at): void
    {
        $log = new AuditLog([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'meta' => $meta,
            'ip' => $ip,
        ]);
        $log->created_at = $at;
        $log->save();
    }

    // --- catalogue lookups ------------------------------------------------

    /** @param  array<int, Course>  $courses  @return array<int, Course> */
    private function paidCourses(array $courses): array
    {
        return array_filter($courses, fn (Course $c) => ! $c->is_free && ! $c->is_center && $c->deleted_at === null && $c->visibility->value === 'visible');
    }

    /** @param  array<int, Course>  $courses */
    private function firstFreeCourse(array $courses): ?Course
    {
        foreach ($courses as $c) {
            if ($c->is_free) {
                return $c;
            }
        }

        return null;
    }

    /** @param  array<int, Course>  $courses */
    private function firstCenterCourse(array $courses): ?Course
    {
        foreach ($courses as $c) {
            if ($c->is_center) {
                return $c;
            }
        }

        return null;
    }

    /** @param  array<int, Course>  $courses */
    private function courseIndexById(array $courses, int $id): int
    {
        foreach ($courses as $i => $c) {
            if ($c->id === $id) {
                return $i;
            }
        }

        return array_key_first($courses);
    }

    private function walletOf(Tenant $tenant, User $user): Wallet
    {
        return Wallet::where('user_id', $user->id)->firstOrFail();
    }
}

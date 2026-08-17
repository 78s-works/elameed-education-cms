<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Assessment\Enums\AttemptStatus;
use App\Modules\Assessment\Enums\ExamGradingMode;
use App\Modules\Assessment\Enums\ExamPassMode;
use App\Modules\Assessment\Enums\ExamType;
use App\Modules\Assessment\Enums\QuestionType;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Assessment\Models\ExamTimeExtension;
use App\Modules\Assessment\Models\Question;
use App\Modules\Billing\Enums\BillingInterval;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\SubscriptionPackage;
use App\Modules\Billing\Models\TenantSubscription;
use App\Modules\Catalog\Enums\AccessMode;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Enums\DependencyEnforcement;
use App\Modules\Catalog\Enums\DependencyTrigger;
use App\Modules\Catalog\Enums\ExtensionStatus;
use App\Modules\Catalog\Enums\GateRule;
use App\Modules\Catalog\Enums\LessonSectionType;
use App\Modules\Catalog\Enums\PdfKind;
use App\Modules\Catalog\Enums\SectionDelivery;
use App\Modules\Catalog\Enums\VideoSource;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\ContentDependency;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\CourseCategory;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonAccessWindow;
use App\Modules\Catalog\Models\LessonExtensionRequest;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Models\PackageType;
use App\Modules\Catalog\Models\PartPassOverride;
use App\Modules\Catalog\Services\AcademicYearContext;
use App\Modules\Catalog\Services\ContentAccessOverrideService;
use App\Modules\Catalog\Services\PackageItemService;
use App\Modules\Catalog\Enums\ContentAccessTarget;
use App\Modules\Centers\Enums\CodeStatus;
use App\Modules\Centers\Enums\CodeType;
use App\Modules\Centers\Models\ActivationCode;
use App\Modules\Centers\Models\AttendanceRecord;
use App\Modules\Centers\Models\Center;
use App\Modules\Centers\Models\CenterExamGrade;
use App\Modules\Centers\Models\CenterIdCode;
use App\Modules\Commerce\Enums\CouponType;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Enums\OrderStatus;
use App\Modules\Commerce\Models\Coupon;
use App\Modules\Commerce\Models\Invoice;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Commerce\Models\Payment;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Engagement\Enums\CommentStatus;
use App\Modules\Engagement\Enums\TicketPriority;
use App\Modules\Engagement\Enums\TicketStatus;
use App\Modules\Engagement\Models\Attachment;
use App\Modules\Engagement\Models\Badge;
use App\Modules\Engagement\Models\Comment;
use App\Modules\Engagement\Models\Favorite;
use App\Modules\Engagement\Models\LessonProgress;
use App\Modules\Engagement\Models\Review;
use App\Modules\Engagement\Models\StudentBadge;
use App\Modules\Engagement\Models\SupportTicket;
use App\Modules\Engagement\Models\TicketReply;
use App\Modules\Engagement\Services\PointsService;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\LoginAttempt;
use App\Modules\Identity\Models\OtpCode as OtpCodeModel;
use App\Modules\Identity\Models\ParentLink;
use App\Modules\Identity\Models\ParentMagicLink;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Enums\MediaVersionState;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaVersion;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationModule;
use App\Modules\Notifications\Enums\NotificationSeverity;
use App\Modules\Notifications\Enums\NotificationTypeStatus;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationChannelSetting;
use App\Modules\Notifications\Models\NotificationEvent;
use App\Modules\Notifications\Models\NotificationMessage;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Models\NotificationType;
use App\Modules\Reporting\Models\AuditLog;
use App\Modules\Tenancy\Enums\TenantDomainType;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\Models\TeacherMeta;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use App\Modules\Wallet\Services\PaymentReceiptService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Real-world tenant seed modelled on https://ahmedtammam.com — د. أحمد تمّام's
 * biology & integrated-science academy for Egyptian secondary school (ثانوي).
 *
 * "Lean but complete": every table gets rows, and the HIGH-VALUE logic paths get
 * DIVERSE rows so every branch shows up in the UI —
 *   - three academic years (الأول/الثاني/الثالث الثانوي), year-scoped content;
 *   - lessons across all access channels (online / center / both) + a free preview;
 *   - chapter (بابي) and monthly (شهري) package types, recursive package items;
 *   - the full paid path (order → payment → invoice → enrollment → ledger) AND
 *     manual / center / code / wallet grants side-by-side;
 *   - exams with MCQ + true/false + essay questions and attempts that pass, fail
 *     and sit in-progress; content dependencies + access windows + extensions;
 *   - wallet top-ups via ledger + payment receipts (approved / pending / rejected);
 *   - percent & fixed coupons (active + expired); reviews, comments, favorites,
 *     badges, points, support tickets; centers with codes, attendance and grades;
 *   - notifications, media assets, audit logs, OTP + login attempts.
 *
 * Idempotent: the whole academy is skipped if its tenant already exists.
 * Credentials (all password `password`):
 *   - teacher   01200000001  (tenant `ahmedtammam.com`)
 *   - students  012001<YY><NN>  e.g. 01200110 1 (year 1), 01200130 1 (year 3)…
 */
class AhmedTammamAcademySeeder extends Seeder
{
    private const SLUG = 'ahmedtammam.com';

    private const CURRENCY = 'EGP';

    private TenantContext $tenantContext;

    private AcademicYearContext $yearContext;

    private EnrollmentService $enroll;

    private PackageItemService $packageItems;

    private LedgerService $ledger;

    private PointsService $points;

    private PaymentReceiptService $receipts;

    private ContentAccessOverrideService $overrides;

    /** @var array<string, AcademicYear> keyed by year label */
    private array $years = [];

    private Tenant $tenant;

    private User $teacher;

    /** @var Center[] */
    private array $centers = [];

    public function run(): void
    {
        if (Tenant::query()->where('slug', self::SLUG)->exists()) {
            $this->command?->info('Academy `'.self::SLUG.'` already seeded — skipping.');

            return;
        }

        $this->tenantContext = app(TenantContext::class);
        $this->yearContext = app(AcademicYearContext::class);
        $this->enroll = app(EnrollmentService::class);
        $this->packageItems = app(PackageItemService::class);
        $this->ledger = app(LedgerService::class);
        $this->points = app(PointsService::class);
        $this->receipts = app(PaymentReceiptService::class);
        $this->overrides = app(ContentAccessOverrideService::class);

        DB::transaction(fn () => $this->seed());

        $this->yearContext->forget();
        $this->tenantContext->forget();

        $this->command?->info('Seeded academy `'.self::SLUG.'` (د. أحمد تمّام) with full, diverse demo data.');
    }

    private function seed(): void
    {
        $this->seedTenantAndTeacher();
        $this->seedSubscription();
        $this->seedCenters();
        $this->seedAcademicYears();
        $this->seedAssistants();
        $this->seedGlobalCoupons();

        // Year 3 is the flagship (biology, الثالث الثانوي) — deepest catalogue.
        $this->seedYearThree();
        // Years 2 and 1 are lighter but complete.
        $this->seedYearTwo();
        $this->seedYearOne();

        $this->seedCrossCutting();
    }

    // ---------------------------------------------------------------- tenancy

    private function seedTenantAndTeacher(): void
    {
        $this->teacher = $this->makeUser('01200000001', 'د. أحمد تمّام', 'ahmed.tammam@elameed.app');

        $this->tenant = Tenant::create([
            'slug' => self::SLUG,
            'name' => 'أكاديمية د. أحمد تمّام للأحياء والعلوم المتكاملة',
            'status' => TenantStatus::Active->value,
            'owner_user_id' => $this->teacher->id,
            'trial_ends_at' => now()->addMonths(1),
        ]);
        $this->tenantContext->setTenant($this->tenant);

        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->teacher->id,
            'role' => TenantUserRole::Teacher->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now()->subMonths(9),
        ]);

        // Custom domain (verified) + platform subdomain.
        TenantDomain::create([
            'tenant_id' => $this->tenant->id,
            'host' => 'ahmedtammam.com',
            'type' => TenantDomainType::Custom->value,
            'is_primary' => true,
            'ssl_status' => 'active',
            'verified_at' => now()->subMonths(8),
        ]);
        TenantDomain::create([
            'tenant_id' => $this->tenant->id,
            'host' => 'ahmed-tammam.elameed.app',
            'type' => TenantDomainType::Subdomain->value,
            'is_primary' => false,
            'verified_at' => now()->subMonths(9),
        ]);

        $profile = new TeacherProfile([
            'bio' => 'طبيب وباحث بكلية طب جامعة عين شمس، ومدرس متخصص في شرح منهج الأحياء للثانوية العامة والعلوم المتكاملة. صاحب «أفضل سبورة في مصر» ومعدّ كتاب «المايسترو».',
            'contact' => ['phone' => '01200000001', 'whatsapp' => '01200000001', 'email' => 'info@ahmedtammam.com'],
            'socials' => ['facebook' => 'https://facebook.com/ahmedtammam', 'youtube' => 'https://youtube.com/@ahmedtammam', 'tiktok' => 'https://tiktok.com/@ahmedtammam'],
            'locales' => ['ar'],
            'primary_locale' => 'ar',
            'layout' => 'classic',
            'hide_ranking' => false,
            'login_enabled' => true,
            'registration_enabled' => true,
            'registration_verification_mode' => 'auto',
            'custom_landing_enabled' => true,
        ]);
        $profile->tenant_id = $this->tenant->id;
        $profile->primary_color = '#0E7C66';
        $profile->secondary_color = '#F2A900';
        $profile->logo_url = 'https://ahmedtammam.com/assets/logo.png';
        $profile->save();

        // Marketing meta shown on the landing page.
        $meta = [
            ['stats', 'students_count', '5000'],
            ['stats', 'rating', '4.9'],
            ['stats', 'experience_years', '9'],
            ['stats', 'lectures_count', '320'],
            ['about', 'headline', 'افهم الأحياء صح… من على السبورة'],
            ['about', 'book', 'كتاب المايسترو'],
        ];
        foreach ($meta as $i => [$group, $key, $value]) {
            $row = new TeacherMeta(['group' => $group, 'key' => $key, 'value' => $value, 'sort_order' => $i]);
            $row->tenant_id = $this->tenant->id;
            $row->save();
        }

        // Tenant-level notification channel settings.
        foreach (['database', 'sms'] as $channel) {
            $cs = new NotificationChannelSetting(['channel' => $channel, 'is_active' => true, 'config' => ['sender' => 'AhmedTammam']]);
            $cs->tenant_id = $this->tenant->id;
            $cs->save();
        }
    }

    private function seedSubscription(): void
    {
        // Platform (global) subscription packages the tenant can be on.
        $starter = SubscriptionPackage::firstOrCreate(
            ['slug' => 'starter'],
            [
                'name' => 'الباقة المبتدئة',
                'description' => 'للمدرّس الفردي في بداية رحلته.',
                'price_minor' => 49900,
                'currency' => self::CURRENCY,
                'interval' => BillingInterval::Monthly->value,
                'trial_days' => 14,
                'limits' => ['max_students' => 500, 'max_courses' => 10, 'storage_mb' => 20480, 'max_assistants' => 2],
                'is_active' => true,
                'sort_order' => 1,
            ],
        );
        $pro = SubscriptionPackage::firstOrCreate(
            ['slug' => 'pro'],
            [
                'name' => 'الباقة الاحترافية',
                'description' => 'لأكاديمية بأعداد طلاب كبيرة وفريق عمل.',
                'price_minor' => 149900,
                'currency' => self::CURRENCY,
                'interval' => BillingInterval::Monthly->value,
                'trial_days' => 14,
                'limits' => ['max_students' => 20000, 'max_courses' => 200, 'storage_mb' => 512000, 'max_assistants' => 20],
                'is_active' => true,
                'sort_order' => 2,
            ],
        );

        TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'package_id' => $pro->id,
            'status' => SubscriptionStatus::Active->value,
            'price_minor' => $pro->price_minor,
            'currency' => self::CURRENCY,
            'started_at' => now()->subMonths(9),
            'renews_at' => now()->addMonth(),
        ]);

        $this->tenant->forceFill(['package_id' => $pro->id])->save();
    }

    private function seedCenters(): void
    {
        $defs = [
            ['سنتر المعادي', 'المعادي، القاهرة', '0225200001'],
            ['سنتر مدينة نصر', 'مدينة نصر، القاهرة', '0224010002'],
        ];
        foreach ($defs as [$name, $address, $phone]) {
            $center = new Center(['name' => $name, 'address' => $address, 'phone' => $phone, 'is_active' => true]);
            $center->tenant_id = $this->tenant->id;
            $center->save();
            $this->centers[] = $center;
        }
    }

    private function seedAcademicYears(): void
    {
        $labels = ['الأول الثانوي', 'الثاني الثانوي', 'الثالث الثانوي'];
        foreach ($labels as $i => $label) {
            $year = new AcademicYear(['name' => $label, 'sort_order' => $i]);
            $year->tenant_id = $this->tenant->id;
            $year->save();
            $this->years[$label] = $year;
        }
    }

    private function seedAssistants(): void
    {
        $all = array_values($this->years); // 3 years
        $year3 = $this->years['الثالث الثانوي'];

        // Assistant serving ALL years (multi-year pivot).
        $menna = $this->makeUser('01200000002', 'أ. منة الله (مساعدة)', 'menna.ta@elameed.app');
        $m1 = TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $menna->id,
            'role' => TenantUserRole::Assistant->value,
            'status' => MembershipStatus::Active->value,
            'permissions' => ['students', 'support', 'homework'],
            'joined_at' => now()->subMonths(4),
        ]);
        $m1->academicYears()->sync(array_map(fn ($y) => $y->id, $all));

        // Assistant scoped to the graduating year only (single-year pivot).
        $ali = $this->makeUser('01200000003', 'أ. علي حسن (مساعد)', 'ali.ta@elameed.app');
        $m2 = TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $ali->id,
            'role' => TenantUserRole::Assistant->value,
            'status' => MembershipStatus::Active->value,
            'permissions' => ['students', 'finance'],
            'joined_at' => now()->subMonths(2),
        ]);
        $m2->academicYears()->sync([$year3->id]);
    }

    private function seedGlobalCoupons(): void
    {
        // Cart-wide percent coupon (active) and a fixed one (expired) — both branches.
        $c1 = new Coupon([
            'code' => 'WELCOME25',
            'type' => CouponType::Percent->value,
            'value' => 25,
            'min_subtotal_minor' => 10000,
            'usage_limit' => 1000,
            'starts_at' => now()->subMonths(2),
            'expires_at' => now()->addMonths(2),
            'is_active' => true,
        ]);
        $c1->tenant_id = $this->tenant->id;
        $c1->save();

        $c2 = new Coupon([
            'code' => 'EID50',
            'type' => CouponType::Fixed->value,
            'value' => 5000, // 50 EGP off
            'usage_limit' => 200,
            'starts_at' => now()->subMonths(6),
            'expires_at' => now()->subMonths(4), // expired
            'is_active' => true,
        ]);
        $c2->tenant_id = $this->tenant->id;
        $c2->save();
    }

    // ------------------------------------------------------------- year three

    private function seedYearThree(): void
    {
        $year = $this->years['الثالث الثانوي'];
        $this->yearContext->set($year->id);

        $category = $this->makeCategory($year, 'أحياء — الثالث الثانوي', 'أحياء', 'الثالث الثانوي');

        $course = $this->makeCourse($year, $category, [
            'title' => 'كورس الأحياء الشامل — الثالث الثانوي',
            'subtitle' => 'المنهج كامل بالخرائط الذهنية على أفضل سبورة في مصر',
            'description' => 'شرح منهج الأحياء للصف الثالث الثانوي بالكامل: الدعامة والحركة، التنسيق الهرموني، الإخراج، التكاثر، المناعة والوراثة.',
            'price_minor' => 120000,
            'access_days' => 300,
            'points' => 500,
            'access_mode' => AccessMode::Both,
        ]);

        // Chapter lessons across all channels; first is a free preview.
        $lessons = [];
        $lessons[] = $this->makeLesson($year, $course, [
            'title' => 'الباب الأول — الدعامة والحركة',
            'access_mode' => AccessMode::Both,
            'is_free_preview' => true,
            'price_minor' => 0,
            'is_purchasable' => false,
            'sort_order' => 1,
        ], withExam: true, essay: false);

        $lessons[] = $this->makeLesson($year, $course, [
            'title' => 'الباب الثاني — التنسيق الهرموني',
            'access_mode' => AccessMode::Online,
            'price_minor' => 25000,
            'is_purchasable' => true,
            'sort_order' => 2,
        ], withExam: true, essay: true);

        $lessons[] = $this->makeLesson($year, $course, [
            'title' => 'الباب الثالث — الإخراج',
            'access_mode' => AccessMode::Center,
            'price_minor' => 25000,
            'is_purchasable' => true,
            'sort_order' => 3,
        ], withExam: true, essay: false);

        $lessons[] = $this->makeLesson($year, $course, [
            'title' => 'الباب الرابع — المناعة والوراثة',
            'access_mode' => AccessMode::Both,
            'price_minor' => 30000,
            'is_purchasable' => true,
            'sort_order' => 4,
        ], withExam: false, essay: false);

        // A content dependency: the quiz of lesson 2 requires passing lesson 1's quiz.
        $this->linkDependency($lessons[0], $lessons[1]);

        // Package types: chapter (بابي) + monthly (شهري).
        $chapterType = $this->makePackageType($year, 'اشتراك بابي', 'hybrid', buyAlone: true);
        $monthlyType = $this->makePackageType($year, 'اشتراك شهري', 'hybrid', buyAlone: true);

        // Chapter package bundling three lessons (recursive items).
        $chapterPkg = $this->makePackage($year, $chapterType, [
            'name' => 'باقة الأبواب — الثالث الثانوي',
            'description' => 'الأبواب الأساسية في اشتراك واحد بسعر أوفر.',
            'price_minor' => 60000,
            'access_mode' => AccessMode::Both,
        ]);
        $this->packageItems->attach($chapterPkg, 'lesson', $lessons[1]->id);
        $this->packageItems->attach($chapterPkg, 'lesson', $lessons[3]->id);

        // Monthly package (single lesson) + nests the chapter package (package-in-package).
        $monthlyPkg = $this->makePackage($year, $monthlyType, [
            'name' => 'الاشتراك الشهري — الثالث الثانوي',
            'description' => 'وصول شهري لأحدث المحاضرات.',
            'price_minor' => 20000,
            'access_mode' => AccessMode::Both,
        ]);
        $this->packageItems->attach($monthlyPkg, 'lesson', $lessons[3]->id);

        // A standalone unit exam (مراجعة نهائية) with mixed questions.
        $unitExam = $this->makeExam($year, $course, null, [
            'title' => 'المراجعة النهائية — الثالث الثانوي',
            'type' => ExamType::UnitExam,
            'grading_mode' => ExamGradingMode::Manual,
            'attempts_allowed' => 2,
            'duration_min' => 120,
        ], essay: true);

        // Students pinned to year 3 (online, center, both) with rich activity.
        $s1 = $this->makeStudent($year, '01200130001', 'يوسف عادل', 'online', 'ذكر', 'القاهرة');
        $s2 = $this->makeStudent($year, '01200130002', 'مريم خالد', 'center', 'أنثى', 'الجيزة', $this->centers[0]);
        $s3 = $this->makeStudent($year, '01200130003', 'عمر محمود', 'both', 'ذكر', 'القليوبية', $this->centers[1]);

        // --- s1: full paid path (order → payment → invoice → enrollment → ledger).
        $this->paidPurchase($s1, $chapterPkg, 'package', $chapterPkg->price_minor, couponPercent: 25);
        $this->progressAndAttempt($s1, $lessons[1], passed: true);
        $this->progressAndAttempt($s1, $lessons[3], passed: false);
        $this->reviewCourse($s1, $course, 5, 'أفضل شرح أحياء على الإطلاق، ربّنا يكرمك يا دكتور.');
        $this->favorite($s1, $course);
        $this->points->award($this->tenant->id, $s1->id, 150, 'exam_passed', 'lesson', $lessons[1]->id);

        // --- s2: center student — code + center enrolments, attendance, center grade.
        $this->enroll->grantLesson($this->tenant->id, $s2->id, $lessons[2], EnrollmentSource::Center);
        $this->enroll->grantLesson($this->tenant->id, $s2->id, $lessons[0], EnrollmentSource::Manual);
        $this->attendance($s2, $this->centers[0], $course, present: true);
        $this->attendance($s2, $this->centers[0], $course, present: false);
        $this->centerGrade($year, $s2, $this->centers[0], 'اختبار الباب الثالث', 40, 34);
        $this->progressAndAttempt($s2, $lessons[2], passed: true);
        $this->reviewCourse($s2, $course, 4, 'الشرح في السنتر ممتاز والمتابعة مستمرة.');

        // --- s3: wallet path — top up via receipt then buy from wallet balance.
        $this->walletTopupViaReceipt($s3, 30000, 'vodafone_cash', 'approved');
        $this->walletTopupViaReceipt($s3, 15000, 'instapay', 'pending');
        $this->walletPurchase($s3, $lessons[1], $lessons[1]->price_minor);
        $this->enroll->grantExam($this->tenant->id, $s3->id, $unitExam, EnrollmentSource::Manual);
        $this->attemptExam($s3, $unitExam, status: AttemptStatus::InProgress, passed: null);

        // Content access override (manual free grant) + a lesson extension request.
        $this->overrides->grant($this->tenant->id, $s1->id, ContentAccessTarget::Lesson, $lessons[2]->id, $this->teacher->id, 'منحة تعويض غياب');
        $this->extensionRequest($s1, $lessons[1]);

        // Comments (thread) + support ticket.
        $this->commentThread($s1, $lessons[1]);
        $this->supportTicket($s3, TicketStatus::Open, TicketPriority::Urgent, 'الفيديو مش بيفتح', 'المحاضرة التانية بتقف عند دقيقة ٣.');

        $this->yearContext->forget();
    }

    // --------------------------------------------------------------- year two

    private function seedYearTwo(): void
    {
        $year = $this->years['الثاني الثانوي'];
        $this->yearContext->set($year->id);

        $category = $this->makeCategory($year, 'أحياء — الثاني الثانوي', 'أحياء', 'الثاني الثانوي');
        $course = $this->makeCourse($year, $category, [
            'title' => 'كورس الأحياء — الثاني الثانوي',
            'subtitle' => 'الطاقة، التغذية، النقل والتنفس',
            'description' => 'أساسيات الأحياء للصف الثاني الثانوي مع بنك أسئلة على كل درس.',
            'price_minor' => 90000,
            'access_days' => 240,
            'points' => 400,
            'access_mode' => AccessMode::Both,
        ]);

        $l1 = $this->makeLesson($year, $course, [
            'title' => 'الطاقة وأنظمة الحياة',
            'access_mode' => AccessMode::Both,
            'is_free_preview' => true,
            'price_minor' => 0,
            'is_purchasable' => false,
            'sort_order' => 1,
        ], withExam: true, essay: false);
        $l2 = $this->makeLesson($year, $course, [
            'title' => 'التغذية والتمثيل الغذائي (البناء الضوئي)',
            'access_mode' => AccessMode::Online,
            'price_minor' => 20000,
            'is_purchasable' => true,
            'sort_order' => 2,
        ], withExam: true, essay: false);
        $l3 = $this->makeLesson($year, $course, [
            'title' => 'النقل في الكائنات الحية',
            'access_mode' => AccessMode::Both,
            'price_minor' => 20000,
            'is_purchasable' => true,
            'sort_order' => 3,
        ], withExam: false, essay: false);

        $type = $this->makePackageType($year, 'اشتراك بابي', 'hybrid', buyAlone: true);
        $pkg = $this->makePackage($year, $type, [
            'name' => 'باقة الترم الأول — الثاني الثانوي',
            'description' => 'محاضرات الترم الأول كاملة.',
            'price_minor' => 45000,
            'access_mode' => AccessMode::Both,
        ]);
        $this->packageItems->attach($pkg, 'lesson', $l2->id);
        $this->packageItems->attach($pkg, 'lesson', $l3->id);

        $s1 = $this->makeStudent($year, '01200120001', 'حبيبة سمير', 'online', 'أنثى', 'الإسكندرية');
        $s2 = $this->makeStudent($year, '01200120002', 'كريم أشرف', 'center', 'ذكر', 'الجيزة', $this->centers[1]);

        // Course purchase (paid) with fixed-price flow, no coupon.
        $this->paidPurchase($s1, $course, 'course', $course->price_minor);
        $this->progressAndAttempt($s1, $l2, passed: true);
        $this->reviewCourse($s1, $course, 5, 'المنهج بقى سهل بعد الخرائط الذهنية.');
        $this->favorite($s1, $course);

        // Manual grant + attendance for the center student.
        $this->enroll->grantPackage($this->tenant->id, $s2->id, $pkg, EnrollmentSource::Manual);
        $this->attendance($s2, $this->centers[1], $course, present: true);
        $this->progressAndAttempt($s2, $l2, passed: false);
        $this->supportTicket($s2, TicketStatus::Closed, TicketPriority::Normal, 'استفسار عن كتاب المايسترو', 'الكتاب متوفر في السنتر ولا أونلاين؟');

        $this->yearContext->forget();
    }

    // --------------------------------------------------------------- year one

    private function seedYearOne(): void
    {
        $year = $this->years['الأول الثانوي'];
        $this->yearContext->set($year->id);

        $category = $this->makeCategory($year, 'علوم متكاملة — الأول الثانوي', 'علوم متكاملة', 'الأول الثانوي');
        $course = $this->makeCourse($year, $category, [
            'title' => 'العلوم المتكاملة — الأول الثانوي',
            'subtitle' => 'أحياء وكيمياء وفيزياء في منهج واحد',
            'description' => 'شرح العلوم المتكاملة للصف الأول الثانوي بأسلوب مبسّط ومنظّم.',
            'price_minor' => 70000,
            'access_days' => 210,
            'points' => 300,
            'access_mode' => AccessMode::Online,
        ]);

        $l1 = $this->makeLesson($year, $course, [
            'title' => 'الخلية — وحدة بناء الكائن الحي',
            'access_mode' => AccessMode::Online,
            'is_free_preview' => true,
            'price_minor' => 0,
            'is_purchasable' => false,
            'sort_order' => 1,
        ], withExam: true, essay: false);
        $l2 = $this->makeLesson($year, $course, [
            'title' => 'المادة وتركيبها',
            'access_mode' => AccessMode::Online,
            'price_minor' => 15000,
            'is_purchasable' => true,
            'sort_order' => 2,
        ], withExam: true, essay: false);

        $type = $this->makePackageType($year, 'اشتراك شهري', 'online', buyAlone: true);
        $pkg = $this->makePackage($year, $type, [
            'name' => 'الاشتراك الشهري — الأول الثانوي',
            'description' => 'وصول شهري لكل المحاضرات أونلاين.',
            'price_minor' => 18000,
            'access_mode' => AccessMode::Online,
        ]);
        $this->packageItems->attach($pkg, 'lesson', $l2->id);

        $s1 = $this->makeStudent($year, '01200110001', 'ملك إبراهيم', 'online', 'أنثى', 'المنوفية');

        $this->paidPurchase($s1, $pkg, 'package', $pkg->price_minor);
        $this->progressAndAttempt($s1, $l2, passed: true);
        $this->reviewCourse($s1, $course, 4, 'مقدمة ممتازة للثانوي.');
        // A visible testimonial with no linked user (author_name only).
        $t = new Review(['course_id' => $course->id, 'rating' => 5, 'author_name' => 'ولي أمر — أ. سامية', 'comment' => 'ابنتي اتحسّن مستواها كتير.', 'is_visible' => true]);
        $t->tenant_id = $this->tenant->id;
        $t->academic_year_id = $year->id;
        $t->user_id = null;
        $t->save();

        $this->yearContext->forget();
    }

    // ------------------------------------------------------------ cross-cutting

    private function seedCrossCutting(): void
    {
        // Parent linked to a year-3 student, with a magic link.
        $year3 = $this->years['الثالث الثانوي'];
        $studentUser = User::query()->where('phone', '01200130001')->first();
        $parent = $this->makeUser('01200099001', 'والد يوسف عادل', 'parent.youssef@elameed.app');
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $parent->id,
            'role' => TenantUserRole::Parent->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now()->subMonths(2),
        ]);
        $link = new ParentLink(['parent_user_id' => $parent->id, 'student_user_id' => $studentUser->id, 'relation' => 'father']);
        $link->tenant_id = $this->tenant->id;
        $link->save();

        $magic = new ParentMagicLink([
            'parent_user_id' => $parent->id,
            'token_hash' => hash('sha256', Str::random(48)),
            'is_active' => true,
        ]);
        $magic->tenant_id = $this->tenant->id;
        $magic->save();

        // Badges + one award to the top year-3 student.
        $badgeStar = $this->makeBadge('نجم الأسبوع', 'الأعلى تفاعلاً هذا الأسبوع', 100);
        $badgeTop = $this->makeBadge('المتفوق', 'اجتاز كل اختبارات الباب', 300);
        $sb = new StudentBadge(['user_id' => $studentUser->id, 'badge_id' => $badgeStar->id, 'awarded_at' => now()->subDays(3)]);
        $sb->tenant_id = $this->tenant->id;
        $sb->academic_year_id = $year3->id;
        $sb->save();

        // Center id-codes (batch) + activation codes (wallet + course, active/redeemed).
        $this->centerIdCodes($year3, $this->centers[0], grade: 3, count: 3);
        $this->activationCodes();

        // A media asset (ready HLS) + version for the flagship free lesson.
        $freeLesson = Lesson::query()->where('title', 'الباب الأول — الدعامة والحركة')->first();
        if ($freeLesson) {
            $asset = new MediaAsset([
                'lesson_id' => $freeLesson->id,
                'type' => MediaType::HlsVideo->value,
                'status' => MediaStatus::Ready->value,
                'provider' => 'bunny',
                'title' => 'محاضرة الدعامة والحركة',
                'duration_sec' => 3600,
                'downloadable' => false,
            ]);
            $asset->tenant_id = $this->tenant->id;
            $asset->save();
            $version = new MediaVersion([
                'media_asset_id' => $asset->id,
                'version' => 1,
                'provider' => 'bunny',
                'state' => MediaVersionState::Ready->value,
                'host_video_id' => (string) Str::uuid(),
                'playback_id' => (string) Str::uuid(),
                'duration_sec' => 3600,
                'ready_at' => now()->subMonths(3),
            ]);
            $version->tenant_id = $this->tenant->id;
            $version->save();
            $asset->forceFill(['current_version_id' => $version->id])->save();
        }

        // Notifications: a dispatched event + per-student in-app message + legacy row.
        $type = NotificationType::firstOrCreate(
            ['key' => 'lessons.lesson.available'],
            ['module' => NotificationModule::Lessons->value, 'severity' => NotificationSeverity::Info->value, 'is_system' => true, 'status' => NotificationTypeStatus::Ready->value],
        );
        $event = NotificationEvent::create([
            'notification_type_id' => $type->id,
            'tenant_id' => $this->tenant->id,
            'entity_type' => Lesson::class,
            'entity_id' => $freeLesson?->id,
            'payload' => ['lesson' => 'الباب الأول — الدعامة والحركة'],
            'triggered_by' => $this->teacher->id,
        ]);
        $msg = new NotificationMessage([
            'notification_event_id' => $event->id,
            'user_id' => $studentUser->id,
            'channel' => NotificationChannel::Database->value,
            'title' => 'محاضرة جديدة متاحة',
            'body' => 'تم إتاحة محاضرة «الباب الأول — الدعامة والحركة».',
            'is_read' => false,
        ]);
        $msg->tenant_id = $this->tenant->id;
        $msg->save();

        $legacy = new Notification([
            'user_id' => $studentUser->id,
            'channel' => 'in_app',
            'type' => 'welcome',
            'payload' => ['message' => 'أهلاً بك في أكاديمية د. أحمد تمّام'],
            'status' => 'sent',
            'sent_at' => now()->subMonths(2),
            'read_at' => now()->subMonths(2)->addHours(3),
        ]);
        $legacy->tenant_id = $this->tenant->id;
        $legacy->save();

        // OTP + login attempts + audit logs (security/reporting surfaces).
        OtpCodeModel::create([
            'identifier' => '01200130001',
            'channel' => 'sms',
            'purpose' => OtpPurpose::Login->value,
            'code_hash' => hash('sha256', '123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
        ]);
        LoginAttempt::create([
            'user_id' => $studentUser->id,
            'tenant_id' => $this->tenant->id,
            'identifier' => '01200130001',
            'ip' => '156.200.10.15',
            'user_agent' => 'Mozilla/5.0 (Android 13)',
            'success' => true,
        ]);
        LoginAttempt::create([
            'user_id' => null,
            'tenant_id' => $this->tenant->id,
            'identifier' => '01200139999',
            'ip' => '156.200.10.99',
            'user_agent' => 'Mozilla/5.0',
            'success' => false,
        ]);
        AuditLog::create([
            'tenant_id' => $this->tenant->id,
            'actor_user_id' => $this->teacher->id,
            'action' => 'lesson.published',
            'subject_type' => Lesson::class,
            'subject_id' => $freeLesson?->id,
            'meta' => ['title' => 'الباب الأول — الدعامة والحركة'],
            'ip' => '197.54.1.1',
        ]);

        // A part-pass override (teacher waives a quiz gate for a student).
        $quizSection = LessonSection::query()
            ->where('academic_year_id', $year3->id)
            ->where('type', LessonSectionType::Quiz->value)
            ->first();
        $s2 = User::query()->where('phone', '01200130002')->first();
        if ($quizSection && $s2) {
            $ppo = new PartPassOverride([
                'lesson_section_id' => $quizSection->id,
                'user_id' => $s2->id,
                'granted_by' => $this->teacher->id,
                'granted_at' => now()->subDays(2),
                'note' => 'اجتاز الاختبار في السنتر ورقياً.',
            ]);
            $ppo->tenant_id = $this->tenant->id;
            $ppo->academic_year_id = $year3->id;
            $ppo->save();
        }

        // An exam time-extension (granted) on the year-3 unit exam.
        $unitExam = Exam::query()->where('academic_year_id', $year3->id)->where('type', ExamType::UnitExam->value)->first();
        $s3 = User::query()->where('phone', '01200130003')->first();
        if ($unitExam && $s3) {
            $ext = new ExamTimeExtension([
                'exam_id' => $unitExam->id,
                'user_id' => $s3->id,
                'requested_minutes' => 20,
                'granted_minutes' => 15,
                'status' => ExtensionStatus::Granted->value,
                'requested_at' => now()->subDays(1),
                'decided_at' => now()->subHours(20),
                'decided_by' => $this->teacher->id,
            ]);
            $ext->tenant_id = $this->tenant->id;
            $ext->academic_year_id = $year3->id;
            $ext->save();
        }

        // A per-user notification preference (student muted SMS).
        NotificationPreference::create([
            'user_id' => $studentUser->id,
            'notification_type_id' => $type->id,
            'channel' => NotificationChannel::Sms->value,
            'is_enabled' => false,
        ]);
    }

    // ------------------------------------------------------------------ helpers

    private function makeUser(string $phone, string $name, string $email): User
    {
        return User::create([
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'password' => 'password',
            'locale' => 'ar',
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
        ]);
    }

    private function makeStudent(AcademicYear $year, string $phone, string $name, string $studyMode, string $gender, string $governorate, ?Center $center = null): User
    {
        $user = $this->makeUser($phone, $name, $phone.'@student.ahmedtammam.com');
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now()->subMonths(rand(1, 6)),
        ]);
        $profile = new StudentProfile([
            'user_id' => $user->id,
            'academic_year_id' => $year->id,
            'academic_year' => $year->name,
            'study_mode' => $studyMode,
            'gender' => $gender,
            'governorate' => $governorate,
            'education_type' => 'عام',
            'guardian_phone' => '0120099'.substr($phone, -4),
            'center_id' => $center?->id,
        ]);
        $profile->tenant_id = $this->tenant->id;
        $profile->save();

        return $user;
    }

    private function makeCategory(AcademicYear $year, string $name, string $subject, string $grade): CourseCategory
    {
        $cat = new CourseCategory(['name' => $name, 'subject' => $subject, 'grade' => $grade, 'sort_order' => $year->sort_order]);
        $cat->tenant_id = $this->tenant->id;
        $cat->academic_year_id = $year->id;
        $cat->save();

        return $cat;
    }

    private function makeCourse(AcademicYear $year, CourseCategory $category, array $attrs): Course
    {
        $course = new Course(array_merge([
            'currency' => self::CURRENCY,
            'visibility' => ContentVisibility::Visible->value,
            'is_free' => false,
            'purchase_enabled' => true,
        ], $attrs));
        $course->tenant_id = $this->tenant->id;
        $course->academic_year_id = $year->id;
        $course->category_id = $category->id;
        $course->slug = Course::makeUniqueSlug($attrs['title']);
        $course->access_mode = $attrs['access_mode'] ?? AccessMode::Both;
        $course->save();

        return $course;
    }

    private function makeLesson(AcademicYear $year, Course $course, array $attrs, bool $withExam, bool $essay): Lesson
    {
        $lesson = new Lesson(array_merge([
            'currency' => self::CURRENCY,
            'visibility' => ContentVisibility::Visible->value,
            'active_video_source' => VideoSource::Youtube->value,
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'duration_sec' => 3600,
            'availability_days' => 14,
            'max_extensions' => 2,
            'extension_hours' => 48,
        ], $attrs));
        $lesson->tenant_id = $this->tenant->id;
        $lesson->academic_year_id = $year->id;
        $lesson->course_id = $course->id;
        $lesson->access_mode = $attrs['access_mode'];
        $lesson->save();

        $this->makeSections($year, $lesson, $withExam ? $this->makeExam($year, $course, $lesson, [
            'title' => 'اختبار: '.$attrs['title'],
            'type' => ExamType::LessonQuiz,
            'grading_mode' => $essay ? ExamGradingMode::Manual : ExamGradingMode::Auto,
        ], $essay) : null);

        return $lesson;
    }

    private function makeSections(AcademicYear $year, Lesson $lesson, ?Exam $exam): void
    {
        // Video (على السبورة).
        $video = new LessonSection([
            'lesson_id' => $lesson->id,
            'type' => LessonSectionType::Video->value,
            'title' => 'الشرح على السبورة',
            'sort_order' => 1,
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'is_required' => true,
        ]);
        $video->tenant_id = $this->tenant->id;
        $video->academic_year_id = $year->id;
        $video->access_mode = $lesson->access_mode->value;
        $video->save();

        // PDF: mind map / Maestro notes.
        $pdf = new LessonSection([
            'lesson_id' => $lesson->id,
            'type' => LessonSectionType::Pdf->value,
            'title' => 'الخريطة الذهنية — كتاب المايسترو',
            'sort_order' => 2,
            'pdf_kind' => PdfKind::LectureNotes->value,
            'is_required' => false,
        ]);
        $pdf->tenant_id = $this->tenant->id;
        $pdf->academic_year_id = $year->id;
        $pdf->access_mode = $lesson->access_mode->value;
        $pdf->save();

        // Homework (upload) gated on submit.
        $hw = new LessonSection([
            'lesson_id' => $lesson->id,
            'type' => LessonSectionType::Homework->value,
            'title' => 'واجب الحصة',
            'sort_order' => 3,
            'delivery' => SectionDelivery::PdfUpload->value,
            'gate_rule' => GateRule::MustSubmit->value,
            'max_tries' => 3,
            'is_required' => true,
        ]);
        $hw->tenant_id = $this->tenant->id;
        $hw->academic_year_id = $year->id;
        $hw->access_mode = $lesson->access_mode->value;
        $hw->save();

        // Quiz section linked to the lesson exam, gated must_pass.
        if ($exam) {
            $quiz = new LessonSection([
                'lesson_id' => $lesson->id,
                'type' => LessonSectionType::Quiz->value,
                'title' => 'اختبار بعد الحصة',
                'sort_order' => 4,
                'exam_id' => $exam->id,
                'gate_rule' => GateRule::MustPass->value,
                'is_required' => true,
            ]);
            $quiz->tenant_id = $this->tenant->id;
            $quiz->academic_year_id = $year->id;
            $quiz->access_mode = $lesson->access_mode->value;
            $quiz->save();
        }
    }

    private function linkDependency(Lesson $prereq, Lesson $dependent): void
    {
        $prereqQuiz = LessonSection::query()->where('lesson_id', $prereq->id)->where('type', LessonSectionType::Quiz->value)->first();
        $depQuiz = LessonSection::query()->where('lesson_id', $dependent->id)->where('type', LessonSectionType::Quiz->value)->first();
        if (! $prereqQuiz || ! $depQuiz) {
            return;
        }
        $dep = new ContentDependency([
            'section_id' => $depQuiz->id,
            'depends_on_section_id' => $prereqQuiz->id,
            'trigger' => DependencyTrigger::Passed->value,
            'enforcement' => DependencyEnforcement::Mandatory->value,
        ]);
        $dep->tenant_id = $this->tenant->id;
        $dep->academic_year_id = $prereq->academic_year_id;
        $dep->save();
    }

    private function makePackageType(AcademicYear $year, string $name, string $channel, bool $buyAlone): PackageType
    {
        $type = new PackageType(['name' => $name, 'channel' => $channel, 'buy_alone' => $buyAlone, 'sort_order' => 0]);
        $type->tenant_id = $this->tenant->id;
        $type->academic_year_id = $year->id;
        $type->save();

        return $type;
    }

    private function makePackage(AcademicYear $year, PackageType $type, array $attrs): Package
    {
        $pkg = new Package(array_merge([
            'currency' => self::CURRENCY,
            'is_purchasable' => true,
            'package_type_id' => $type->id,
        ], $attrs));
        $pkg->tenant_id = $this->tenant->id;
        $pkg->academic_year_id = $year->id;
        $pkg->access_mode = $attrs['access_mode'] ?? AccessMode::Both;
        $pkg->save();

        return $pkg;
    }

    private function makeExam(AcademicYear $year, Course $course, ?Lesson $lesson, array $attrs, bool $essay): Exam
    {
        $exam = new Exam(array_merge([
            'pass_percent' => 50,
            'pass_mode' => ExamPassMode::Percent->value,
            'attempts_allowed' => 1,
            'duration_min' => 30,
            'is_published' => true,
            'show_answers' => true,
        ], $attrs));
        $exam->tenant_id = $this->tenant->id;
        $exam->academic_year_id = $year->id;
        $exam->course_id = $course->id;
        $exam->lesson_id = $lesson?->id;
        $exam->type = ($attrs['type'] ?? ExamType::LessonQuiz)->value;
        $exam->grading_mode = ($attrs['grading_mode'] ?? ExamGradingMode::Auto)->value;
        $exam->total_marks = $essay ? 15 : 10;
        $exam->save();

        $this->makeQuestions($year, $exam, $essay);

        return $exam;
    }

    private function makeQuestions(AcademicYear $year, Exam $exam, bool $essay): void
    {
        $rows = [
            [QuestionType::Mcq, 'أي العضيات مسؤولة عن إنتاج الطاقة في الخلية؟', ['النواة', 'الميتوكوندريا', 'الرايبوسوم', 'الجهاز الجولجي'], [1], 5],
            [QuestionType::TrueFalse, 'الحمض النووي DNA يوجد داخل النواة.', ['صح', 'خطأ'], [0], 5],
        ];
        if ($essay) {
            $rows[] = [QuestionType::Essay, 'اشرح آلية التنسيق الهرموني في جسم الإنسان مع الأمثلة.', null, null, 5];
        }
        foreach ($rows as $i => [$type, $body, $options, $correct, $points]) {
            $q = new Question([
                'type' => $type->value,
                'body' => $body,
                'options' => $options,
                'correct' => $correct,
                'points' => $points,
                'sort_order' => $i + 1,
            ]);
            $q->tenant_id = $this->tenant->id;
            $q->academic_year_id = $year->id;
            $q->exam_id = $exam->id;
            $q->save();
        }
    }

    // -- commerce -------------------------------------------------------------

    /** Full paid path: order + item + payment + invoice + enrollment + ledger. */
    private function paidPurchase(User $user, $item, string $type, int $priceMinor, ?int $couponPercent = null): void
    {
        $discount = $couponPercent ? intdiv($priceMinor * $couponPercent, 100) : 0;
        $total = max(0, $priceMinor - $discount);
        $coupon = $couponPercent ? Coupon::query()->where('code', 'WELCOME25')->first() : null;

        $order = new Order([
            'user_id' => $user->id,
            'subtotal_minor' => $priceMinor,
            'discount_minor' => $discount,
            'total_minor' => $total,
            'currency' => self::CURRENCY,
            'coupon_id' => $coupon?->id,
            'status' => OrderStatus::Paid->value,
        ]);
        $order->tenant_id = $this->tenant->id;
        $order->save();

        $order->items()->create([
            'tenant_id' => $this->tenant->id,
            'item_type' => $type,
            'item_id' => $item->id,
            'price_minor' => $priceMinor,
            'title' => $item->name ?? $item->title,
        ]);

        $payment = new Payment([
            'order_id' => $order->id,
            'gateway' => 'paymob',
            'gateway_txn_id' => 'PM-'.strtoupper(Str::random(10)),
            'amount_minor' => $total,
            'status' => 'paid',
            'reference_number' => (string) rand(100000, 999999),
            'processed_at' => now()->subDays(rand(1, 20)),
        ]);
        $payment->tenant_id = $this->tenant->id;
        $payment->save();

        $this->makeInvoice($order);

        if ($coupon) {
            $coupon->increment('used_count');
        }

        // Ledger: gateway clearing debit, teacher earnings (85%) + platform (15%).
        $commission = intdiv($total * 15, 100);
        $this->ledger->post($this->tenant->id, 'order:'.$order->id, [
            ['account' => LedgerEntry::GATEWAY_CLEARING, 'direction' => LedgerEntry::DEBIT, 'amount_minor' => $total],
            ['account' => LedgerEntry::TEACHER_EARNINGS, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => $total - $commission],
            ['account' => LedgerEntry::PLATFORM_COMMISSION, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => $commission],
        ], 'order', $order->id);

        // Grant access.
        if ($type === 'package') {
            $this->enroll->grantPackage($this->tenant->id, $user->id, $item, EnrollmentSource::Purchase);
        } elseif ($type === 'course') {
            $this->enroll->grantCourse($this->tenant->id, $user->id, $item, EnrollmentSource::Purchase);
        } else {
            $this->enroll->grantLesson($this->tenant->id, $user->id, $item, EnrollmentSource::Purchase);
        }
    }

    /** Buy a lesson from wallet balance (funding = student wallet). */
    private function walletPurchase(User $user, Lesson $lesson, int $priceMinor): void
    {
        $order = new Order([
            'user_id' => $user->id,
            'subtotal_minor' => $priceMinor,
            'discount_minor' => 0,
            'total_minor' => $priceMinor,
            'currency' => self::CURRENCY,
            'status' => OrderStatus::Paid->value,
        ]);
        $order->tenant_id = $this->tenant->id;
        $order->save();
        $order->items()->create([
            'tenant_id' => $this->tenant->id,
            'item_type' => 'lesson',
            'item_id' => $lesson->id,
            'price_minor' => $priceMinor,
            'title' => $lesson->title,
        ]);

        $payment = new Payment([
            'order_id' => $order->id,
            'gateway' => 'wallet',
            'gateway_txn_id' => 'W-'.strtoupper(Str::random(10)),
            'amount_minor' => $priceMinor,
            'status' => 'paid',
            'processed_at' => now()->subDays(rand(1, 10)),
        ]);
        $payment->tenant_id = $this->tenant->id;
        $payment->save();
        $this->makeInvoice($order);

        $wallet = $this->ledger->walletFor($this->tenant->id, $user->id);
        $commission = intdiv($priceMinor * 15, 100);
        $this->ledger->post($this->tenant->id, 'order:'.$order->id, [
            ['account' => LedgerEntry::STUDENT_WALLET, 'direction' => LedgerEntry::DEBIT, 'amount_minor' => $priceMinor, 'wallet_id' => $wallet->id],
            ['account' => LedgerEntry::TEACHER_EARNINGS, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => $priceMinor - $commission],
            ['account' => LedgerEntry::PLATFORM_COMMISSION, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => $commission],
        ], 'order', $order->id);

        $this->enroll->grantLesson($this->tenant->id, $user->id, $lesson, EnrollmentSource::Wallet);
    }

    private function makeInvoice(Order $order): void
    {
        $next = (int) Invoice::query()->where('tenant_id', $this->tenant->id)->max('number') + 1;
        $inv = new Invoice([
            'order_id' => $order->id,
            'number' => $next,
            'issued_at' => now(),
        ]);
        $inv->tenant_id = $this->tenant->id;
        $inv->save();
    }

    /** Top up wallet through a payment receipt (approved credits the ledger). */
    private function walletTopupViaReceipt(User $user, int $amountMinor, string $method, string $outcome): void
    {
        $attachment = new Attachment([
            'kind' => 'image',
            'storage_key' => 'receipts/'.Str::uuid().'.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => rand(50000, 400000),
            'uploaded_by' => $user->id,
        ]);
        $attachment->tenant_id = $this->tenant->id;
        $attachment->save();

        $receipt = $this->receipts->submit($this->tenant->id, $user->id, $method, $amountMinor, $attachment->id, self::CURRENCY);

        if ($outcome === 'approved') {
            $this->receipts->approve($receipt, $this->teacher);
        } elseif ($outcome === 'rejected') {
            $this->receipts->reject($receipt, $this->teacher, 'الصورة غير واضحة، برجاء إعادة الإرسال.');
        }
        // 'pending' left as-is.
    }

    // -- engagement / progress ------------------------------------------------

    private function progressAndAttempt(User $user, Lesson $lesson, bool $passed): void
    {
        $percent = $passed ? 100 : rand(30, 70);
        $prog = new LessonProgress([
            'lesson_id' => $lesson->id,
            'user_id' => $user->id,
            'watch_percent' => $percent,
            'watch_seconds' => (int) ($lesson->duration_sec * $percent / 100),
            'sessions_count' => rand(1, 5),
            'last_position_sec' => (int) ($lesson->duration_sec * $percent / 100),
            'completed_at' => $passed ? now()->subDays(rand(1, 15)) : null,
        ]);
        $prog->tenant_id = $this->tenant->id;
        $prog->academic_year_id = $lesson->academic_year_id;
        $prog->save();

        $exam = Exam::query()->where('lesson_id', $lesson->id)->first();
        if ($exam) {
            $this->attemptExam($user, $exam, $passed ? AttemptStatus::Graded : AttemptStatus::Submitted, $passed);
        }
    }

    private function attemptExam(User $user, Exam $exam, AttemptStatus $status, ?bool $passed): void
    {
        $max = (int) ($exam->total_marks ?? 10);
        $score = $status === AttemptStatus::InProgress ? null : ($passed ? $max : intdiv($max, 3));
        $attempt = new ExamAttempt([
            'exam_id' => $exam->id,
            'user_id' => $user->id,
            'attempt_number' => 1,
            'started_at' => now()->subDays(rand(1, 12)),
            'submitted_at' => $status === AttemptStatus::InProgress ? null : now()->subDays(rand(0, 11)),
            'score' => $score,
            'max_score' => $max,
            'status' => $status->value,
            'answers' => ['1' => 1, '2' => 0],
            'needs_manual_grade' => $exam->grading_mode === ExamGradingMode::Manual->value && $status !== AttemptStatus::Graded,
        ]);
        $attempt->tenant_id = $this->tenant->id;
        $attempt->academic_year_id = $exam->academic_year_id;
        $attempt->save();
    }

    private function reviewCourse(User $user, Course $course, int $rating, string $comment): void
    {
        $r = new Review(['course_id' => $course->id, 'rating' => $rating, 'comment' => $comment, 'is_visible' => true]);
        $r->tenant_id = $this->tenant->id;
        $r->academic_year_id = $course->academic_year_id;
        $r->user_id = $user->id;
        $r->save();
    }

    private function favorite(User $user, Course $course): void
    {
        $f = new Favorite(['user_id' => $user->id, 'course_id' => $course->id]);
        $f->tenant_id = $this->tenant->id;
        $f->academic_year_id = $course->academic_year_id;
        $f->save();
    }

    private function commentThread(User $student, Lesson $lesson): void
    {
        $q = new Comment([
            'lesson_id' => $lesson->id,
            'user_id' => $student->id,
            'body' => 'يا دكتور، ممكن توضيح أكتر عن الغدة النخامية؟',
            'status' => CommentStatus::Answered->value,
        ]);
        $q->tenant_id = $this->tenant->id;
        $q->academic_year_id = $lesson->academic_year_id;
        $q->save();

        $a = new Comment([
            'lesson_id' => $lesson->id,
            'user_id' => $this->teacher->id,
            'parent_id' => $q->id,
            'body' => 'أكيد، شوف الدقيقة ١٢ في الفيديو، وفيه خريطة ذهنية في الملف المرفق.',
            'status' => CommentStatus::Answered->value,
        ]);
        $a->tenant_id = $this->tenant->id;
        $a->academic_year_id = $lesson->academic_year_id;
        $a->save();

        // A pending (new) comment awaiting moderation.
        $p = new Comment([
            'lesson_id' => $lesson->id,
            'user_id' => $student->id,
            'body' => 'شكراً على الشرح الرائع 🙏',
            'status' => CommentStatus::New->value,
        ]);
        $p->tenant_id = $this->tenant->id;
        $p->academic_year_id = $lesson->academic_year_id;
        $p->save();
    }

    private function supportTicket(User $user, TicketStatus $status, TicketPriority $priority, string $subject, string $body): void
    {
        $ticket = new SupportTicket([
            'user_id' => $user->id,
            'assigned_to' => $status !== TicketStatus::Open ? $this->teacher->id : null,
            'subject' => $subject,
            'body' => $body,
            'priority' => $priority->value,
            'status' => $status->value,
        ]);
        $ticket->tenant_id = $this->tenant->id;
        $ticket->save();

        if ($status !== TicketStatus::Open) {
            $reply = new TicketReply([
                'ticket_id' => $ticket->id,
                'user_id' => $this->teacher->id,
                'body' => 'تم استلام طلبك ونعمل على حله، شكراً لصبرك.',
            ]);
            $reply->tenant_id = $this->tenant->id;
            $reply->save();
        }
    }

    private function extensionRequest(User $user, Lesson $lesson): void
    {
        $window = LessonAccessWindow::query()
            ->where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->first();
        if (! $window) {
            return;
        }
        $req = new LessonExtensionRequest([
            'access_window_id' => $window->id,
            'user_id' => $user->id,
            'status' => ExtensionStatus::Pending->value,
            'requested_at' => now()->subDays(1),
        ]);
        $req->tenant_id = $this->tenant->id;
        $req->academic_year_id = $lesson->academic_year_id;
        $req->save();
    }

    // -- centers --------------------------------------------------------------

    private function attendance(User $user, Center $center, Course $course, bool $present): void
    {
        $rec = new AttendanceRecord([
            'center_id' => $center->id,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'attended_on' => now()->subDays($present ? rand(1, 5) : rand(6, 12))->toDateString(),
            'status' => $present ? 'present' : 'absent',
            'marked_by' => $this->teacher->id,
            'source' => 'center',
        ]);
        $rec->tenant_id = $this->tenant->id;
        $rec->academic_year_id = $course->academic_year_id;
        $rec->save();
    }

    private function centerGrade(AcademicYear $year, User $user, Center $center, string $title, float $total, float $score): void
    {
        $g = new CenterExamGrade([
            'center_id' => $center->id,
            'student_user_id' => $user->id,
            'title' => $title,
            'total_marks' => $total,
            'score' => $score,
            'sat_on' => now()->subDays(rand(3, 20))->toDateString(),
            'entered_by' => $this->teacher->id,
        ]);
        $g->tenant_id = $this->tenant->id;
        $g->academic_year_id = $year->id;
        $g->save();
    }

    private function centerIdCodes(AcademicYear $year, Center $center, int $grade, int $count): void
    {
        $batch = (string) Str::uuid();
        for ($i = 1; $i <= $count; $i++) {
            $code = new CenterIdCode([
                'center_id' => $center->id,
                'grade' => $grade,
                'sequence' => $i,
                'code' => sprintf('C%d-%03d-%s', $grade, $i, strtoupper(Str::random(4))),
                'status' => $i === 1 ? CodeStatus::Redeemed->value : CodeStatus::Active->value,
                'batch_id' => $batch,
                'generated_by' => $this->teacher->id,
            ]);
            $code->tenant_id = $this->tenant->id;
            $code->academic_year_id = $year->id;
            $code->save();
        }
    }

    private function activationCodes(): void
    {
        // Wallet code (active) + course code (redeemed).
        $wallet = new ActivationCode([
            'code' => 'WAL-'.strtoupper(Str::random(6)),
            'type' => CodeType::Wallet->value,
            'amount_minor' => 20000,
            'center_id' => $this->centers[0]->id,
            'generated_by' => $this->teacher->id,
            'batch' => 'batch-wallet-1',
            'status' => CodeStatus::Active->value,
            'expires_at' => now()->addMonths(3),
        ]);
        $wallet->tenant_id = $this->tenant->id;
        $wallet->save();

        $courseCode = new ActivationCode([
            'code' => 'CRS-'.strtoupper(Str::random(6)),
            'type' => CodeType::Course->value,
            'center_id' => $this->centers[1]->id,
            'generated_by' => $this->teacher->id,
            'batch' => 'batch-course-1',
            'status' => CodeStatus::Redeemed->value,
            'redeemed_by' => User::query()->where('phone', '01200130002')->value('id'),
            'redeemed_at' => now()->subDays(10),
        ]);
        $courseCode->tenant_id = $this->tenant->id;
        $courseCode->save();
    }

    private function makeBadge(string $name, string $desc, int $threshold): Badge
    {
        $b = new Badge(['name' => $name, 'description' => $desc, 'points_threshold' => $threshold, 'icon' => '🏅']);
        $b->tenant_id = $this->tenant->id;
        $b->save();

        return $b;
    }
}

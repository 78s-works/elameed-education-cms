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
use App\Modules\Catalog\Enums\ContentAccessTarget;
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
use App\Modules\Catalog\Models\ContentAccessOverride;
use App\Modules\Catalog\Models\ContentDependency;
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
use App\Modules\Centers\Enums\CodeStatus;
use App\Modules\Centers\Enums\CodeType;
use App\Modules\Centers\Models\ActivationCode;
use App\Modules\Centers\Models\AttendanceRecord;
use App\Modules\Centers\Models\Center;
use App\Modules\Centers\Models\CenterExamGrade;
use App\Modules\Centers\Models\CenterIdCode;
use App\Modules\Centers\Models\CenterSession;
use App\Modules\Centers\Services\CenterSessionAttendanceService;
use App\Modules\Commerce\Enums\CouponType;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Enums\OrderStatus;
use App\Modules\Commerce\Models\Coupon;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Models\Invoice;
use App\Modules\Commerce\Models\Order;
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
use App\Modules\Tenancy\Models\TeacherMeta;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use App\Modules\Wallet\Services\PaymentReceiptService;
use App\Modules\Catalog\Enums\AssignmentKind;
use App\Modules\Media\Models\MediaCallbackEvent;
use App\Modules\Media\Models\MediaRendition;
use App\Modules\Media\Models\MediaUploadSession;
use App\Modules\Media\Models\PlaybackSession;
use App\Modules\Notifications\Models\NotificationFailure;
use App\Modules\Notifications\Models\NotificationLog;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\Models\NotificationTemplateTranslation;
use App\Modules\Tenancy\Support\LandingSchema;
use Illuminate\Database\Seeder;
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

    /** @var string[] */
    private const MALE_NAMES = [
        'أحمد سعيد',
        'محمود عبد الله',
        'مصطفى ياسر',
        'خالد وليد',
        'زياد أيمن',
        'عبد الرحمن هشام',
        'كريم طارق',
        'مازن فؤاد',
        'يحيى إبراهيم',
        'أنس رمضان',
        'باسل نبيل',
        'حمزة صلاح',
        'مروان عصام',
        'سيف الدين جمال',
        'آدم مجدي',
        'طه شريف',
        'إياد ماهر',
        'رامي فتحي',
        'عمرو حمدي',
        'شادي لطفي',
        'فارس عادل',
        'نور الدين سمير',
        'وليد عاطف',
        'هاني رفعت',
    ];

    /** @var string[] */
    private const FEMALE_NAMES = [
        'سلمى محمد',
        'نور هاني',
        'جنى أشرف',
        'ملك تامر',
        'رنا وائل',
        'هبة عماد',
        'دينا رجب',
        'ياسمين فوزي',
        'مايا شوقي',
        'فاطمة الزهراء',
        'آية منير',
        'ندى بهاء',
        'لينا حسام',
        'روان طلعت',
        'شهد ممدوح',
        'مروة سعد',
        'إسراء زكي',
        'تقى مختار',
        'حنين علاء',
        'رقية سراج',
        'بسملة أنور',
        'أسماء رأفت',
        'رودينا كمال',
        'ميرنا فادي',
    ];

    /** @var string[] */
    private const GOVERNORATES = [
        'القاهرة',
        'الجيزة',
        'الإسكندرية',
        'القليوبية',
        'الدقهلية',
        'الشرقية',
        'الغربية',
        'المنوفية',
        'البحيرة',
        'كفر الشيخ',
        'أسيوط',
        'سوهاج',
        'المنيا',
        'الفيوم',
        'بني سويف',
        'قنا',
        'أسوان',
        'الأقصر',
        'دمياط',
        'بورسعيد',
        'الإسماعيلية',
        'السويس',
        'مطروح',
        'شمال سيناء',
    ];

    private TenantContext $tenantContext;

    private AcademicYearContext $yearContext;

    private EnrollmentService $enroll;

    private PackageItemService $packageItems;

    private LedgerService $ledger;

    private PointsService $points;

    private PaymentReceiptService $receipts;

    private ContentAccessOverrideService $overrides;

    private CenterSessionAttendanceService $sessionAttendance;

    /** @var array<string, AcademicYear> keyed by year label */
    private array $years = [];

    private Tenant $tenant;

    private User $teacher;

    /** @var Center[] */
    private array $centers = [];

    /** Running per-(center,grade) ID-code sequence, so minted codes never collide. */
    private array $idCodeSeq = [];

    /** Shared batch for student-bound (redeemed) ID codes. */
    private ?string $studentCodeBatch = null;

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
        $this->sessionAttendance = app(CenterSessionAttendanceService::class);

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
        // Fills the columns/tables the happy paths above never touch (media
        // pipeline, notification delivery trail, archived + revoked rows), so no
        // nullable column is left 100% NULL in a freshly seeded database.
        $this->seedOperationalTrail();
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
            // Cloudflare custom-hostname id, set once the domain is onboarded.
            'cf_custom_hostname_id' => 'cf-'.Str::lower(Str::random(24)),
            'verified_at' => now()->subMonths(8),
        ]);
        TenantDomain::create([
            'tenant_id' => $this->tenant->id,
            'host' => 'ahmed-tammam.elameed.app',
            'type' => TenantDomainType::Subdomain->value,
            'is_primary' => false,
            'ssl_status' => 'active',
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
        $profile->favicon_url = 'https://ahmedtammam.com/assets/favicon.png';
        $profile->cover_url = 'https://ahmedtammam.com/assets/cover.jpg';
        // A saved landing layout (the schema's own starter), so the CMS section
        // builder loads real persisted sections instead of falling back to defaults.
        $profile->landing_sections = LandingSchema::defaults('ar');
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

        // A coupon scoped to ONE content target is a different pricing branch from
        // the two cart-wide ones above; the package it points at is created later,
        // so it is bound in seedOperationalTrail().
        $c3 = new Coupon([
            'code' => 'PKG10',
            'type' => CouponType::Percent->value,
            'value' => 10,
            'usage_limit' => 50,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addMonths(3),
            'is_active' => true,
        ]);
        $c3->tenant_id = $this->tenant->id;
        $c3->save();

        // A retired coupon (soft-deleted) so the trashed branch is populated.
        $c4 = new Coupon([
            'code' => 'OLDSUMMER',
            'type' => CouponType::Fixed->value,
            'value' => 2500,
            'usage_limit' => 100,
            'starts_at' => now()->subYear(),
            'expires_at' => now()->subMonths(9),
            'is_active' => false,
        ]);
        $c4->tenant_id = $this->tenant->id;
        $c4->save();
        $c4->delete();
    }

    // ------------------------------------------------------------- year three

    private function seedYearThree(): void
    {
        $year = $this->years['الثالث الثانوي'];
        $this->yearContext->set($year->id);

        // Chapter lessons across all channels; first is a free preview. Lessons are
        // standalone under the year (no course grouping — VD §7); packages sell them.
        $lessons = [];
        $lessons[] = $this->makeLesson($year, [
            'title' => 'الباب الأول — الدعامة والحركة',
            'access_mode' => AccessMode::Both,
            'is_free_preview' => true,
            'price_minor' => 0,
            'is_purchasable' => false,
            'sort_order' => 1,
        ], withExam: true, essay: false);

        $lessons[] = $this->makeLesson($year, [
            'title' => 'الباب الثاني — التنسيق الهرموني',
            'access_mode' => AccessMode::Online,
            'price_minor' => 25000,
            'is_purchasable' => true,
            'sort_order' => 2,
        ], withExam: true, essay: true);

        $lessons[] = $this->makeLesson($year, [
            'title' => 'الباب الثالث — الإخراج',
            'access_mode' => AccessMode::Center,
            'price_minor' => 25000,
            'is_purchasable' => true,
            'sort_order' => 3,
        ], withExam: true, essay: false);

        $lessons[] = $this->makeLesson($year, [
            'title' => 'الباب الرابع — المناعة والوراثة',
            'access_mode' => AccessMode::Both,
            'price_minor' => 30000,
            'is_purchasable' => true,
            'sort_order' => 4,
        ], withExam: false, essay: false);

        $lessons[] = $this->makeLesson($year, [
            'title' => 'الباب الخامس — التكاثر',
            'access_mode' => AccessMode::Online,
            'price_minor' => 22000,
            'is_purchasable' => true,
            'sort_order' => 5,
        ], withExam: true, essay: true);

        $lessons[] = $this->makeLesson($year, [
            'title' => 'الباب السادس — الوراثة الجزيئية',
            'access_mode' => AccessMode::Center,
            'price_minor' => 18000,
            'is_purchasable' => true,
            'sort_order' => 6,
        ], withExam: false, essay: false);

        $lessons[] = $this->makeLesson($year, [
            'title' => 'مراجعة ليلة الامتحان',
            'access_mode' => AccessMode::Both,
            'price_minor' => 35000,
            'is_purchasable' => true,
            'sort_order' => 7,
        ], withExam: true, essay: false);

        // A content dependency: the quiz of lesson 2 requires passing lesson 1's quiz.
        $this->linkDependency($lessons[0], $lessons[1]);

        // Package types: full-course + chapter (بابي) + monthly (شهري).
        $fullType = $this->makePackageType($year, 'الكورس الكامل', 'hybrid', buyAlone: true);
        $chapterType = $this->makePackageType($year, 'اشتراك بابي', 'hybrid', buyAlone: true);
        $monthlyType = $this->makePackageType($year, 'اشتراك شهري', 'hybrid', buyAlone: true);

        // Full-course package (all lessons) — replaces the old "كورس الأحياء الشامل".
        $fullPkg = $this->makePackage($year, $fullType, [
            'name' => 'كورس الأحياء الشامل — الثالث الثانوي',
            'description' => 'شرح منهج الأحياء للصف الثالث الثانوي بالكامل: الدعامة والحركة، التنسيق الهرموني، الإخراج، التكاثر، المناعة والوراثة.',
            'price_minor' => 120000,
            'access_mode' => AccessMode::Both,
        ]);
        foreach ($lessons as $lesson) {
            $this->packageItems->attach($fullPkg, 'lesson', $lesson->id);
        }

        // Chapter package bundling a few lessons (recursive items).
        $chapterPkg = $this->makePackage($year, $chapterType, [
            'name' => 'باقة الأبواب — الثالث الثانوي',
            'description' => 'الأبواب الأساسية في اشتراك واحد بسعر أوفر.',
            'price_minor' => 60000,
            'access_mode' => AccessMode::Both,
        ]);
        $this->packageItems->attach($chapterPkg, 'lesson', $lessons[1]->id);
        $this->packageItems->attach($chapterPkg, 'lesson', $lessons[3]->id);

        // Monthly package (single lesson).
        $monthlyPkg = $this->makePackage($year, $monthlyType, [
            'name' => 'الاشتراك الشهري — الثالث الثانوي',
            'description' => 'وصول شهري لأحدث المحاضرات.',
            'price_minor' => 20000,
            'access_mode' => AccessMode::Both,
        ]);
        $this->packageItems->attach($monthlyPkg, 'lesson', $lessons[3]->id);

        // A standalone review exam (مراجعة نهائية) — free_exam, bound to no lesson.
        $reviewExam = $this->makeExam($year, null, [
            'title' => 'المراجعة النهائية — الثالث الثانوي',
            'type' => ExamType::FreeExam,
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
        $this->reviewLesson($s1, $lessons[1], 5, 'أفضل شرح أحياء على الإطلاق، ربّنا يكرمك يا دكتور.');
        $this->favorite($s1, $lessons[1]);
        $this->points->award($this->tenant->id, $s1->id, 150, 'exam_passed', 'lesson', $lessons[1]->id);

        // --- s2: center student — code + center enrolments, attendance, center grade.
        $this->enroll->grantLesson($this->tenant->id, $s2->id, $lessons[2], EnrollmentSource::Center);
        $this->enroll->grantLesson($this->tenant->id, $s2->id, $lessons[0], EnrollmentSource::Manual);
        $this->attendance($s2, $this->centers[0], $year, present: true);
        $this->attendance($s2, $this->centers[0], $year, present: false);
        $this->centerGrade($year, $s2, $this->centers[0], 'اختبار الباب الثالث', 40, 34);
        $this->progressAndAttempt($s2, $lessons[2], passed: true);
        $this->reviewLesson($s2, $lessons[2], 4, 'الشرح في السنتر ممتاز والمتابعة مستمرة.');

        // --- s3: wallet path — top up via receipt then buy from wallet balance.
        $this->walletTopupViaReceipt($s3, 30000, 'vodafone_cash', 'approved');
        $this->walletTopupViaReceipt($s3, 15000, 'instapay', 'pending');
        // Approved with a CORRECTION: the slip showed less than the student typed,
        // so `corrected_amount_minor` is what actually hit the wallet.
        $this->walletTopupViaReceipt($s3, 25000, 'vodafone_cash', 'corrected');
        $this->walletPurchase($s3, $lessons[1], $lessons[1]->price_minor);
        $this->enroll->grantExam($this->tenant->id, $s3->id, $reviewExam, EnrollmentSource::Manual);
        $this->attemptExam($s3, $reviewExam, status: AttemptStatus::InProgress, passed: null);

        // Content access override (manual free grant) + a lesson extension request.
        $this->overrides->grant($this->tenant->id, $s1->id, ContentAccessTarget::Lesson, $lessons[2]->id, $this->teacher->id, 'منحة تعويض غياب');
        $this->extensionRequest($s1, $lessons[1]);

        // Comments (thread) + support ticket.
        $this->commentThread($s1, $lessons[1]);
        $this->supportTicket($s3, TicketStatus::Open, TicketPriority::Urgent, 'الفيديو مش بيفتح', 'المحاضرة التانية بتقف عند دقيقة ٣.');

        // Center sessions (attendance is taken against these): one per center,
        // bundling the year's center/both lessons, with the center students checked in.
        $this->centerSession($year, $this->centers[0], 'حصة السبت — الأحياء', $lessons, [$s2]);
        $this->centerSession($year, $this->centers[1], 'حصة الثلاثاء — مراجعة', $lessons, [$s3]);

        // Large divergent cohort (flagship year — deepest).
        $this->seedCohort($year, $fullPkg, $lessons, $chapterPkg, yearDigit: 3, count: 22);

        $this->yearContext->forget();
    }

    // --------------------------------------------------------------- year two

    private function seedYearTwo(): void
    {
        $year = $this->years['الثاني الثانوي'];
        $this->yearContext->set($year->id);

        $l1 = $this->makeLesson($year, [
            'title' => 'الطاقة وأنظمة الحياة',
            'access_mode' => AccessMode::Both,
            'is_free_preview' => true,
            'price_minor' => 0,
            'is_purchasable' => false,
            'sort_order' => 1,
        ], withExam: true, essay: false);
        $l2 = $this->makeLesson($year, [
            'title' => 'التغذية والتمثيل الغذائي (البناء الضوئي)',
            'access_mode' => AccessMode::Online,
            'price_minor' => 20000,
            'is_purchasable' => true,
            'sort_order' => 2,
        ], withExam: true, essay: false);
        $l3 = $this->makeLesson($year, [
            'title' => 'النقل في الكائنات الحية',
            'access_mode' => AccessMode::Both,
            'price_minor' => 20000,
            'is_purchasable' => true,
            'sort_order' => 3,
        ], withExam: false, essay: false);
        $l4 = $this->makeLesson($year, [
            'title' => 'التنفس الخلوي',
            'access_mode' => AccessMode::Center,
            'price_minor' => 18000,
            'is_purchasable' => true,
            'sort_order' => 4,
        ], withExam: true, essay: false);
        $l5 = $this->makeLesson($year, [
            'title' => 'الإخراج والاتزان الداخلي',
            'access_mode' => AccessMode::Online,
            'price_minor' => 18000,
            'is_purchasable' => true,
            'sort_order' => 5,
        ], withExam: true, essay: true);

        // Full-course package (all lessons) — replaces the old "كورس الأحياء".
        $fullType = $this->makePackageType($year, 'الكورس الكامل', 'hybrid', buyAlone: true);
        $fullPkg = $this->makePackage($year, $fullType, [
            'name' => 'كورس الأحياء — الثاني الثانوي',
            'description' => 'أساسيات الأحياء للصف الثاني الثانوي مع بنك أسئلة على كل درس.',
            'price_minor' => 90000,
            'access_mode' => AccessMode::Both,
        ]);
        foreach ([$l1, $l2, $l3, $l4, $l5] as $lesson) {
            $this->packageItems->attach($fullPkg, 'lesson', $lesson->id);
        }

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

        // Full-course package purchase (paid) with fixed-price flow, no coupon.
        $this->paidPurchase($s1, $fullPkg, 'package', $fullPkg->price_minor);
        $this->progressAndAttempt($s1, $l2, passed: true);
        $this->reviewLesson($s1, $l2, 5, 'المنهج بقى سهل بعد الخرائط الذهنية.');
        $this->favorite($s1, $l2);

        // Manual grant + attendance for the center student.
        $this->enroll->grantPackage($this->tenant->id, $s2->id, $pkg, EnrollmentSource::Manual);
        $this->attendance($s2, $this->centers[1], $year, present: true);
        $this->progressAndAttempt($s2, $l2, passed: false);
        $this->supportTicket($s2, TicketStatus::Closed, TicketPriority::Normal, 'استفسار عن كتاب المايسترو', 'الكتاب متوفر في السنتر ولا أونلاين؟');

        // Center session (attendance) at the student's center, bundling the year's
        // center/both lessons, with the center student checked in.
        $this->centerSession($year, $this->centers[1], 'حصة الأحد — الأحياء', [$l1, $l2, $l3, $l4, $l5], [$s2]);

        $this->seedCohort($year, $fullPkg, [$l1, $l2, $l3, $l4, $l5], $pkg, yearDigit: 2, count: 14);

        $this->yearContext->forget();
    }

    // --------------------------------------------------------------- year one

    private function seedYearOne(): void
    {
        $year = $this->years['الأول الثانوي'];
        $this->yearContext->set($year->id);

        $l1 = $this->makeLesson($year, [
            'title' => 'الخلية — وحدة بناء الكائن الحي',
            'access_mode' => AccessMode::Online,
            'is_free_preview' => true,
            'price_minor' => 0,
            'is_purchasable' => false,
            'sort_order' => 1,
        ], withExam: true, essay: false);
        $l2 = $this->makeLesson($year, [
            'title' => 'المادة وتركيبها',
            'access_mode' => AccessMode::Online,
            'price_minor' => 15000,
            'is_purchasable' => true,
            'sort_order' => 2,
        ], withExam: true, essay: false);
        $l3 = $this->makeLesson($year, [
            'title' => 'الحركة والقوى',
            'access_mode' => AccessMode::Online,
            'price_minor' => 15000,
            'is_purchasable' => true,
            'sort_order' => 3,
        ], withExam: true, essay: false);

        // Full-course package (all lessons) — replaces the old "العلوم المتكاملة".
        $fullType = $this->makePackageType($year, 'الكورس الكامل', 'online', buyAlone: true);
        $fullPkg = $this->makePackage($year, $fullType, [
            'name' => 'العلوم المتكاملة — الأول الثانوي',
            'description' => 'شرح العلوم المتكاملة للصف الأول الثانوي بأسلوب مبسّط ومنظّم.',
            'price_minor' => 70000,
            'access_mode' => AccessMode::Online,
        ]);
        foreach ([$l1, $l2, $l3] as $lesson) {
            $this->packageItems->attach($fullPkg, 'lesson', $lesson->id);
        }

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
        $this->reviewLesson($s1, $l2, 4, 'مقدمة ممتازة للثانوي.');
        // A visible testimonial with no linked user (author_name only).
        $t = new Review(['target_type' => 'lesson', 'target_id' => $l2->id, 'rating' => 5, 'author_name' => 'ولي أمر — أ. سامية', 'comment' => 'ابنتي اتحسّن مستواها كتير.', 'is_visible' => true]);
        $t->tenant_id = $this->tenant->id;
        $t->academic_year_id = $year->id;
        $t->user_id = null;
        $t->save();

        $this->seedCohort($year, $fullPkg, [$l1, $l2, $l3], $pkg, yearDigit: 1, count: 10);

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

        // An exam time-extension (granted) on the year-3 standalone review exam.
        $reviewExam = Exam::query()->where('academic_year_id', $year3->id)->where('type', ExamType::FreeExam->value)->first();
        $s3 = User::query()->where('phone', '01200130003')->first();
        if ($reviewExam && $s3) {
            $ext = new ExamTimeExtension([
                'exam_id' => $reviewExam->id,
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

    /** A plausible city per governorate, so `student_profiles.region` is never null
     *  (it is a required field on the sign-up form — an empty column here means the
     *  student-management and profile screens render a blank row). */
    private const REGIONS = [
        'القاهرة' => 'مدينة نصر',
        'الجيزة' => 'الدقي',
        'الإسكندرية' => 'سيدي جابر',
        'الدقهلية' => 'المنصورة',
        'الشرقية' => 'الزقازيق',
        'المنوفية' => 'شبين الكوم',
        'القليوبية' => 'بنها',
        'الغربية' => 'طنطا',
        'أسيوط' => 'أسيوط',
        'سوهاج' => 'سوهاج',
    ];

    private function makeStudent(AcademicYear $year, string $phone, string $name, string $studyMode, string $gender, string $governorate, ?Center $center = null, MembershipStatus $status = MembershipStatus::Active, string $educationType = 'عام'): User
    {
        $user = $this->makeUser($phone, $name, $phone.'@student.ahmedtammam.com');
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Student->value,
            'status' => $status->value,
            'joined_at' => now()->subMonths(rand(1, 9))->subDays(rand(0, 28)),
        ]);
        $profile = new StudentProfile([
            'user_id' => $user->id,
            'academic_year_id' => $year->id,
            'academic_year' => $year->name,
            'study_mode' => $studyMode,
            'gender' => $gender,
            'governorate' => $governorate,
            'region' => self::REGIONS[$governorate] ?? 'وسط البلد',
            'education_type' => $educationType,
            'guardian_phone' => '0120099'.substr($phone, -4),
            'center_id' => $center?->id,
        ]);
        $profile->tenant_id = $this->tenant->id;
        $profile->save();

        // Every center-going student (center OR both) carries a redeemed center
        // ID-code — the code they were issued at the center binds their identity.
        if ($center !== null) {
            $this->mintIdCode($year, $center, (int) $year->sort_order + 1, $user);
        }

        return $user;
    }

    private function makeLesson(AcademicYear $year, array $attrs, bool $withExam, bool $essay): Lesson
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
            // Description + view ceiling + publish stamp are all optional columns
            // the panel renders; leaving them null shows empty cards.
            'description' => 'شرح كامل على السبورة، مع خريطة ذهنية وواجب وتقييم بعد الحصة.',
            'max_views' => 5,
            'publish_at' => now()->subMonths(2),
        ], $attrs));
        $lesson->tenant_id = $this->tenant->id;
        $lesson->academic_year_id = $year->id;
        $lesson->access_mode = $attrs['access_mode'];
        // Dormant column (no consumer yet) and not mass-assignable — set directly so
        // the intended shape is at least documented in the data.
        $lesson->gating_rule = ['require_previous_section' => true, 'min_watch_percent' => 80];
        $lesson->save();

        $this->makeSections($year, $lesson, $withExam ? $this->makeExam($year, $lesson, [
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
            // Upload homework: the student uploads a file, a teacher corrects it.
            'assignment_kind' => AssignmentKind::Upload->value,
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
            // Package cards fall back to a placeholder without these.
            'cover_url' => 'https://ahmedtammam.com/assets/packages/cover.jpg',
            'promo_video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ], $attrs));
        $pkg->tenant_id = $this->tenant->id;
        $pkg->academic_year_id = $year->id;
        $pkg->access_mode = $attrs['access_mode'] ?? AccessMode::Both;
        $pkg->save();

        return $pkg;
    }

    private function makeExam(AcademicYear $year, ?Lesson $lesson, array $attrs, bool $essay): Exam
    {
        $exam = new Exam(array_merge([
            'pass_percent' => 50,
            'pass_mode' => ExamPassMode::Percent->value,
            'attempts_allowed' => 1,
            'duration_min' => 30,
            'is_published' => true,
            'show_answers' => true,
            // A real availability window, so the "not open yet / closed" branches
            // have something to compare against.
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->addMonths(4),
        ], $attrs));
        $exam->tenant_id = $this->tenant->id;
        $exam->academic_year_id = $year->id;
        $exam->lesson_id = $lesson?->id;
        $exam->type = ($attrs['type'] ?? ExamType::LessonQuiz)->value;
        $exam->grading_mode = ($attrs['grading_mode'] ?? ExamGradingMode::Auto)->value;
        $exam->total_marks = $essay ? 15 : 10;
        // An essay exam is graded by absolute marks, not a percentage, so it also
        // carries `pass_value` (the marks needed) — exercises ExamPassMode::Marks.
        if ($essay) {
            $exam->pass_mode = ExamPassMode::Marks->value;
            $exam->pass_value = 9;
        }
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
                // Bubble-sheet reference into the printed book (كتاب المايسترو):
                // the paper question this row scores.
                'book_ref' => ['book' => 'كتاب المايسترو', 'page' => 40 + $i, 'qno' => $i + 1],
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
            // The gateway callback body, kept verbatim for reconciliation.
            'raw_payload' => [
                'type' => 'TRANSACTION',
                'obj' => [
                    'success' => true,
                    'amount_cents' => $total,
                    'currency' => self::CURRENCY,
                    'source_data' => ['type' => 'card', 'sub_type' => 'Visa'],
                ],
            ],
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
            // A rendered PDF + the Egyptian e-invoice (ETA) receipt uuid returned
            // when the invoice is reported to the tax authority.
            'pdf_url' => 'https://ahmedtammam.com/invoices/'.$next.'.pdf',
            'eta_receipt_uuid' => (string) Str::uuid(),
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

        if ($outcome === 'corrected') {
            // The teacher read a different figure on the slip than the student
            // typed, so the wallet is credited with the corrected amount.
            $this->receipts->approve($receipt, $this->teacher, (int) round($amountMinor * 0.9));
        } elseif ($outcome === 'approved') {
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
        // Tie the progress row to the grant it belongs to, so the enrollment ->
        // progress join (used by reporting) is exercised.
        $enrollmentId = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->value('id');

        $prog = new LessonProgress([
            'lesson_id' => $lesson->id,
            'user_id' => $user->id,
            'enrollment_id' => $enrollmentId,
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

    private function reviewLesson(User $user, Lesson $lesson, int $rating, string $comment): void
    {
        // Reviews target a lesson|package now (VD §7).
        $r = new Review(['target_type' => 'lesson', 'target_id' => $lesson->id, 'rating' => $rating, 'comment' => $comment, 'is_visible' => true]);
        $r->tenant_id = $this->tenant->id;
        $r->academic_year_id = $lesson->academic_year_id;
        $r->user_id = $user->id;
        $r->save();
    }

    private function favorite(User $user, Lesson $lesson): void
    {
        $f = new Favorite(['user_id' => $user->id, 'target_type' => 'lesson', 'target_id' => $lesson->id]);
        $f->tenant_id = $this->tenant->id;
        $f->academic_year_id = $lesson->academic_year_id;
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

    private function attendance(User $user, Center $center, AcademicYear $year, bool $present): void
    {
        $rec = new AttendanceRecord([
            'center_id' => $center->id,
            'user_id' => $user->id,
            'attended_on' => now()->subDays($present ? rand(1, 5) : rand(6, 12))->toDateString(),
            'status' => $present ? 'present' : 'absent',
            'marked_by' => $this->teacher->id,
            'source' => 'center',
            // `external_ref` is the offline-sync idempotency key the desktop
            // center app sends; `note` is the teacher's free-text remark.
            'external_ref' => 'sync-'.Str::lower(Str::random(12)),
            'note' => $present ? 'حضر الحصة كاملة.' : 'تم إبلاغ ولي الأمر بالغياب.',
        ]);
        $rec->tenant_id = $this->tenant->id;
        $rec->academic_year_id = $year->id;
        $rec->save();
    }

    /**
     * A center session bundling the center-accessible ($center/$both) lessons of
     * the year, plus a check-in for each given student (opens those lessons
     * online). Populates the attendance page's session picker, active grants, and
     * roster. Online-only lessons are skipped — a center session never bundles them.
     *
     * @param  Lesson[]  $lessons  the year's lessons (filtered to center/both here)
     * @param  User[]  $students  center students to check in
     */
    private function centerSession(AcademicYear $year, Center $center, string $name, array $lessons, array $students): void
    {
        $centerLessonIds = collect($lessons)
            ->filter(fn (Lesson $l) => in_array($l->access_mode, [AccessMode::Center, AccessMode::Both], true))
            ->pluck('id')->all();

        $session = new CenterSession(['center_id' => $center->id, 'name' => $name, 'session_at' => now()->subDays(rand(1, 6))]);
        $session->tenant_id = $this->tenant->id;
        $session->academic_year_id = $year->id;
        $session->save();
        $session->lessons()->sync($centerLessonIds);

        $session->load('lessons');
        foreach ($students as $student) {
            $this->sessionAttendance->checkin($this->tenant->id, $center, $session, $student, $this->teacher->id);
        }
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
            'note' => $score >= $total / 2 ? 'مستوى جيد، يحتاج مراجعة الرسومات.' : 'يحتاج إعادة شرح الباب.',
        ]);
        $g->tenant_id = $this->tenant->id;
        $g->academic_year_id = $year->id;
        $g->save();
    }

    /** A spare batch of unused (unbound) ID codes for the ID-codes tab to list. */
    private function centerIdCodes(AcademicYear $year, Center $center, int $grade, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->mintIdCode($year, $center, $grade);
        }
    }

    /**
     * Mint one center ID-code, sequential per (center, grade). Bound + flipped to
     * redeemed when $student is given (their issued identity code); otherwise a
     * fresh unused code. Sequence + code stay unique (matches the live encoder).
     */
    private function mintIdCode(AcademicYear $year, Center $center, int $grade, ?User $student = null): CenterIdCode
    {
        $key = $center->id.'-'.$grade;
        $sequence = $this->idCodeSeq[$key] = ($this->idCodeSeq[$key] ?? 0) + 1;

        $code = new CenterIdCode([
            'center_id' => $center->id,
            'grade' => $grade,
            'sequence' => $sequence,
            'code' => sprintf('%d-%d-%06d', $grade, $center->id, $sequence),
            'status' => $student !== null ? CodeStatus::Redeemed->value : CodeStatus::Active->value,
            'batch_id' => $student !== null
                ? ($this->studentCodeBatch ??= (string) Str::uuid())
                : (string) Str::uuid(),
            'generated_by' => $this->teacher->id,
            'used_by' => $student?->id,
            'used_at' => $student !== null ? now() : null,
        ]);
        $code->tenant_id = $this->tenant->id;
        $code->academic_year_id = $year->id;
        $code->save();

        return $code;
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

        // A historical, already-redeemed CONTENT code. It keeps its target so the
        // redemption history shows WHAT was granted (target_type/target_id are the
        // only record of it once the code is spent).
        $target = Package::query()->orderBy('id')->first();
        $contentCode = new ActivationCode([
            'code' => 'CNT-'.strtoupper(Str::random(6)),
            'type' => CodeType::Content->value,
            'target_type' => ActivationCode::TARGET_PACKAGE,
            'target_id' => $target?->id,
            'center_id' => $this->centers[1]->id,
            'generated_by' => $this->teacher->id,
            'batch' => 'batch-content-1',
            'status' => CodeStatus::Redeemed->value,
            'redeemed_by' => User::query()->where('phone', '01200130002')->value('id'),
            'redeemed_at' => now()->subDays(10),
            'expires_at' => now()->addMonths(6),
        ]);
        $contentCode->tenant_id = $this->tenant->id;
        $contentCode->save();

        // A disabled code and an expired one — the two non-redeemable branches.
        $disabled = new ActivationCode([
            'code' => 'WAL-'.strtoupper(Str::random(6)),
            'type' => CodeType::Wallet->value,
            'amount_minor' => 10000,
            'center_id' => $this->centers[0]->id,
            'generated_by' => $this->teacher->id,
            'batch' => 'batch-wallet-1',
            'status' => CodeStatus::Disabled->value,
            'expires_at' => now()->addMonths(3),
        ]);
        $disabled->tenant_id = $this->tenant->id;
        $disabled->save();

        $expired = new ActivationCode([
            'code' => 'WAL-'.strtoupper(Str::random(6)),
            'type' => CodeType::Wallet->value,
            'amount_minor' => 5000,
            'center_id' => $this->centers[0]->id,
            'generated_by' => $this->teacher->id,
            'batch' => 'batch-wallet-0',
            'status' => CodeStatus::Active->value,
            'expires_at' => now()->subMonth(),
        ]);
        $expired->tenant_id = $this->tenant->id;
        $expired->save();
    }

    // -- bulk cohort (divergence engine) --------------------------------------

    /**
     * Generate a large, DIVERGENT student cohort for a year: every access channel,
     * membership status, enrollment source, order state, exam outcome and review
     * rating is represented, so no UI branch stays empty.
     *
     * @param  Lesson[]  $lessons
     */
    private function seedCohort(AcademicYear $year, Package $mainPkg, array $lessons, ?Package $pkg, int $yearDigit, int $count): void
    {
        $sellable = array_values(array_filter($lessons, fn (Lesson $l) => (int) $l->price_minor > 0));
        if ($sellable === []) {
            $sellable = $lessons; // fall back to any lesson
        }
        $freeLesson = $lessons[0];

        $modes = ['online', 'center', 'both'];
        $eduTypes = ['عام', 'عام', 'عام', 'أزهر', 'لغات'];
        $ratings = [5, 4, 5, 3, 4, 2, 5, 1];
        $reviewText = [
            'شرح ممتاز وسهل الفهم، ربنا يوفقك يا دكتور.',
            'الخرائط الذهنية غيّرت طريقة مذاكرتي.',
            'المتابعة والواجبات محترمة جداً.',
            'الفيديو أحياناً بيقطع بس المحتوى تحفة.',
            'محتاج أمثلة أكتر على المسائل.',
            'أحسن مدرس أحياء اتعاملت معاه.',
            'الاختبارات بعد كل حصة بتثبّت المعلومة.',
            'السعر مناسب مقابل المحتوى.',
        ];
        $ticketSubjects = [
            ['الكود مش شغال', 'اشتريت كود من السنتر وبيقولي غير صالح.'],
            ['استرجاع مبلغ', 'اتخصم مني مرتين على نفس المحاضرة.'],
            ['طلب تفعيل', 'حوّلت على فودافون كاش ولسه مفعّلش.'],
            ['سؤال في الواجب', 'مش لاقي مكان رفع الواجب.'],
        ];

        for ($i = 0; $i < $count; $i++) {
            $seq = 100 + $i;
            $phone = '012001'.$yearDigit.sprintf('%04d', $seq);

            $male = $i % 2 === 0;
            $namePool = $male ? self::MALE_NAMES : self::FEMALE_NAMES;
            $name = $namePool[($i >> 1) % count($namePool)];
            $gender = $male ? 'ذكر' : 'أنثى';
            $mode = $modes[$i % 3];
            $gov = self::GOVERNORATES[$i % count(self::GOVERNORATES)];
            $center = in_array($mode, ['center', 'both'], true) ? $this->centers[$i % count($this->centers)] : null;
            $edu = $eduTypes[$i % count($eduTypes)];

            $status = MembershipStatus::Active;
            if ($i % 11 === 5) {
                $status = MembershipStatus::Pending;   // registered, awaiting approval
            } elseif ($i % 17 === 7) {
                $status = MembershipStatus::Suspended;  // blocked account
            }

            $student = $this->makeStudent($year, $phone, $name, $mode, $gender, $gov, $center, $status, $edu);

            // Pending/suspended members stay inactive — no purchases (realistic funnel drop-off).
            if ($status !== MembershipStatus::Active) {
                if ($i % 2 === 0) {
                    $this->failedOrder($student, $sellable[$i % count($sellable)]);
                }

                continue;
            }

            $lesson = $sellable[$i % count($sellable)];
            $path = $i % 8;
            $hasAccess = true;

            switch ($path) {
                case 0: // full-course package, some with coupon
                    $this->paidPurchase($student, $mainPkg, 'package', $mainPkg->price_minor, couponPercent: $i % 3 === 0 ? 25 : null);
                    break;
                case 1: // paid chapter package (or full-course fallback)
                    if ($pkg) {
                        $this->paidPurchase($student, $pkg, 'package', $pkg->price_minor);
                    } else {
                        $this->paidPurchase($student, $mainPkg, 'package', $mainPkg->price_minor);
                    }
                    break;
                case 2: // wallet: top-up then buy a lesson
                    $this->walletTopupViaReceipt($student, max($lesson->price_minor, 20000), $i % 2 ? 'vodafone_cash' : 'instapay', 'approved');
                    $this->walletPurchase($student, $lesson, $lesson->price_minor);
                    break;
                case 3: // manual teacher grant
                    $this->enroll->grantLesson($this->tenant->id, $student->id, $lesson, EnrollmentSource::Manual);
                    break;
                case 4: // activation-code grant
                    $this->enroll->grantLesson($this->tenant->id, $student->id, $lesson, EnrollmentSource::Code);
                    break;
                case 5: // center enrolment + attendance + paper grade
                    $c = $center ?? $this->centers[0];
                    $this->enroll->grantLesson($this->tenant->id, $student->id, $lesson, EnrollmentSource::Center);
                    $this->attendance($student, $c, $year, present: $i % 4 !== 0);
                    $this->centerGrade($year, $student, $c, 'اختبار الشهر', 40, rand(18, 40));
                    break;
                case 6: // abandoned cart — failed payment, no access
                    $this->failedOrder($student, $lesson);
                    $hasAccess = false;
                    break;
                case 7: // bought then refunded — no access
                    $this->refundedOrder($student, $lesson);
                    $hasAccess = false;
                    break;
            }

            if (! $hasAccess) {
                // Some abandoned/refunded students still leave a support ticket.
                if ($i % 3 === 0) {
                    [$sub, $bd] = $ticketSubjects[$i % count($ticketSubjects)];
                    $this->supportTicket($student, TicketStatus::Open, TicketPriority::Urgent, $sub, $bd);
                }

                continue;
            }

            // Activity divergence for enrolled students.
            $outcome = $i % 3; // 0 pass, 1 fail, 2 in-progress
            if ($outcome === 2) {
                $exam = Exam::query()->where('lesson_id', $lesson->id)->first();
                if ($exam) {
                    $this->enroll->grantExam($this->tenant->id, $student->id, $exam, EnrollmentSource::Manual);
                    $this->attemptExam($student, $exam, AttemptStatus::InProgress, null);
                } else {
                    $this->progressAndAttempt($student, $lesson, passed: false);
                }
            } else {
                $this->progressAndAttempt($student, $lesson, passed: $outcome === 0);
            }

            if ($i % 2 === 0) {
                $this->reviewLesson($student, $lesson, $ratings[$i % count($ratings)], $reviewText[$i % count($reviewText)]);
            }
            if ($i % 3 === 0) {
                $this->favorite($student, $lesson);
            }
            if ($outcome === 0 && $i % 4 === 0) {
                $this->points->award($this->tenant->id, $student->id, 100 + ($i % 5) * 20, 'exam_passed', 'lesson', $lesson->id);
            }
            if ($i % 6 === 1) {
                $this->commentThread($student, $freeLesson);
            }
            if ($i % 9 === 4) {
                [$sub, $bd] = $ticketSubjects[$i % count($ticketSubjects)];
                $st = [TicketStatus::Open, TicketStatus::InProgress, TicketStatus::Closed][$i % 3];
                $pr = [TicketPriority::Normal, TicketPriority::Urgent][$i % 2];
                $this->supportTicket($student, $st, $pr, $sub, $bd);
            }
            // A rejected receipt shows the failed top-up branch.
            if ($i % 13 === 3) {
                $this->walletTopupViaReceipt($student, 10000, 'instapay', 'rejected');
            }
        }
    }

    /** Order that never completed — failed gateway, no invoice / enrolment / ledger. */
    private function failedOrder(User $user, $item): void
    {
        $price = (int) ($item->price_minor ?? 20000);
        $order = new Order([
            'user_id' => $user->id,
            'subtotal_minor' => $price,
            'discount_minor' => 0,
            'total_minor' => $price,
            'currency' => self::CURRENCY,
            'status' => OrderStatus::Failed->value,
        ]);
        $order->tenant_id = $this->tenant->id;
        $order->save();
        $order->items()->create([
            'tenant_id' => $this->tenant->id,
            'item_type' => $item instanceof Lesson ? 'lesson' : 'package',
            'item_id' => $item->id,
            'price_minor' => $price,
            'title' => $item->name ?? $item->title,
        ]);
        $payment = new Payment([
            'order_id' => $order->id,
            'gateway' => 'paymob',
            'gateway_txn_id' => 'PM-'.strtoupper(Str::random(10)),
            'amount_minor' => $price,
            'status' => 'failed',
            'reference_number' => (string) rand(100000, 999999),
            'processed_at' => now()->subDays(rand(1, 15)),
        ]);
        $payment->tenant_id = $this->tenant->id;
        $payment->save();
    }

    /** Order paid then refunded — invoice kept, payment refunded, no active access. */
    private function refundedOrder(User $user, $item): void
    {
        $price = (int) ($item->price_minor ?? 20000);
        $order = new Order([
            'user_id' => $user->id,
            'subtotal_minor' => $price,
            'discount_minor' => 0,
            'total_minor' => $price,
            'currency' => self::CURRENCY,
            'status' => OrderStatus::Refunded->value,
        ]);
        $order->tenant_id = $this->tenant->id;
        $order->save();
        $order->items()->create([
            'tenant_id' => $this->tenant->id,
            'item_type' => $item instanceof Lesson ? 'lesson' : 'package',
            'item_id' => $item->id,
            'price_minor' => $price,
            'title' => $item->name ?? $item->title,
        ]);
        $payment = new Payment([
            'order_id' => $order->id,
            'gateway' => 'paymob',
            'gateway_txn_id' => 'PM-'.strtoupper(Str::random(10)),
            'amount_minor' => $price,
            'status' => 'refunded',
            'reference_number' => (string) rand(100000, 999999),
            'processed_at' => now()->subDays(rand(5, 25)),
        ]);
        $payment->tenant_id = $this->tenant->id;
        $payment->save();
        $this->makeInvoice($order);
    }

    private function makeBadge(string $name, string $desc, int $threshold): Badge
    {
        $b = new Badge(['name' => $name, 'description' => $desc, 'points_threshold' => $threshold, 'icon' => '🏅']);
        $b->tenant_id = $this->tenant->id;
        $b->save();

        return $b;
    }

    // ------------------------------------------------- operational trail (gaps)

    /**
     * Everything the happy paths above never write.
     *
     * The rest of this seeder models what a working academy LOOKS like; this fills
     * the columns and tables that only appear once something is retried, archived,
     * revoked, corrected or delivered — the media pipeline's intermediate rows, the
     * notification delivery trail, soft-deleted content, expiring grants. Without
     * them a freshly seeded database leaves ~70 nullable columns and 6 tables
     * completely empty, so every screen that reads them renders a blank.
     *
     * Deliberately NOT seeded: `cache`, `cache_locks`, `jobs`, `job_batches`,
     * `failed_jobs`, `sessions`, `password_reset_tokens` and
     * `personal_access_tokens` are runtime scratch tables owned by the framework —
     * rows there are transient state, not fixtures.
     */
    private function seedOperationalTrail(): void
    {
        $year3 = $this->years['الثالث الثانوي'];
        $s1 = User::query()->where('phone', '01200130001')->first();
        $s2 = User::query()->where('phone', '01200130002')->first();
        $s3 = User::query()->where('phone', '01200130003')->first();

        $this->trailMediaPipeline($year3, $s1);
        $this->trailNotificationDelivery($s1);
        $this->trailArchivedAndRevoked($year3, $s1, $s2);
        $this->trailGrantsAndGrading($year3, $s1, $s2, $s3);
        $this->trailPlatformRecords();
    }

    /**
     * The media pipeline end to end: a fully-described READY asset, the upload
     * session + host callback that produced it, a per-student encrypted HLS
     * rendition, an issued playback token, and a FAILED version (the retry branch).
     */
    private function trailMediaPipeline(AcademicYear $year, ?User $student): void
    {
        $asset = MediaAsset::query()->whereNotNull('current_version_id')->first();

        if ($asset === null) {
            return;
        }

        // Fill in what a real transcode writes back onto the asset.
        $asset->forceFill([
            'thumbnail_url' => 'https://cdn.ahmedtammam.com/thumbs/'.$asset->uuid.'.jpg',
            'source_key' => 'uploads/'.$asset->uuid.'/master.mp4',
            'hls_path' => 'hls/'.$asset->uuid.'/index.m3u8',
            'encryption_key_ref' => 'kms://elameed/media/'.$asset->uuid,
            'renditions' => [
                ['height' => 360, 'bitrate_kbps' => 800],
                ['height' => 720, 'bitrate_kbps' => 2500],
                ['height' => 1080, 'bitrate_kbps' => 5000],
            ],
            'size_bytes' => 734003200,
            'url' => 'https://cdn.ahmedtammam.com/hls/'.$asset->uuid.'/index.m3u8',
            'watermark_policy' => 'student_phone',
            'access_scope' => 'enrolled',
        ])->save();

        // Point the lesson (and its video part) at the uploaded asset, and switch the
        // active source away from YouTube — the `upload` branch of the player.
        $ownerLesson = Lesson::query()->whereKey($asset->lesson_id)->first();

        if ($ownerLesson !== null) {
            $ownerLesson->forceFill([
                'video_asset_id' => $asset->id,
                'active_video_source' => VideoSource::Upload->value,
            ])->save();

            LessonSection::query()
                ->where('lesson_id', $ownerLesson->id)
                ->where('type', LessonSectionType::Video->value)
                ->update(['media_asset_id' => $asset->id]);
        }

        $ready = MediaVersion::query()->where('media_asset_id', $asset->id)->orderBy('version')->first();

        if ($ready !== null) {
            $ready->forceFill([
                'thumbnail_url' => 'https://cdn.ahmedtammam.com/thumbs/v1-'.$asset->uuid.'.jpg',
                'meta' => ['width' => 1920, 'height' => 1080, 'fps' => 30, 'codec' => 'h264'],
            ])->save();

            // The upload intent that produced this version.
            $session = new MediaUploadSession([
                'media_version_id' => $ready->id,
                'created_by' => $this->teacher->id,
                'idempotency_key' => (string) Str::uuid(),
                'host_upload_id' => 'up_'.Str::lower(Str::random(20)),
                'upload_url' => 'https://upload.bunnycdn.com/'.Str::lower(Str::random(18)),
                'protocol' => 'tus',
                'size_bytes' => 734003200,
                'max_bytes' => 2147483648,
                'content_type' => 'video/mp4',
                'checksum_sha256' => hash('sha256', 'master.mp4'),
                'state' => 'completed',
                'expires_at' => now()->subMonths(3)->addHours(6),
            ]);
            $session->tenant_id = $this->tenant->id;
            $session->save();

            // The host's "asset ready" webhook, already processed (idempotency row).
            MediaCallbackEvent::create([
                'event_id' => 'evt_'.Str::lower(Str::random(24)),
                'tenant_id' => $this->tenant->id,
                'media_version_id' => $ready->id,
                'type' => 'video.ready',
                'payload_hash' => hash('sha256', 'video.ready|'.$ready->id),
                'processed_at' => now()->subMonths(3),
            ]);

            // A per-student encrypted HLS rendition (ready) + one that failed.
            if ($student !== null) {
                $rend = new MediaRendition([
                    'media_asset_id' => $asset->id,
                    'user_id' => $student->id,
                    'status' => 'ready',
                    'hls_dir' => 'renditions/'.$asset->uuid.'/'.$student->id,
                    'enc_key' => bin2hex(random_bytes(16)),
                    'iv' => bin2hex(random_bytes(8)),
                    'segment_count' => 360,
                ]);
                $rend->tenant_id = $this->tenant->id;
                $rend->save();

                $failedRend = new MediaRendition([
                    'media_asset_id' => $asset->id,
                    'user_id' => $this->teacher->id,
                    'status' => 'failed',
                    'hls_dir' => 'renditions/'.$asset->uuid.'/'.$this->teacher->id,
                    'enc_key' => bin2hex(random_bytes(16)),
                    'iv' => bin2hex(random_bytes(8)),
                    'segment_count' => 0,
                    'error' => 'ffmpeg exited with code 1: no such filter "watermark"',
                ]);
                $failedRend->tenant_id = $this->tenant->id;
                $failedRend->save();

                // Two issued playback tokens: one live, one revoked (device switch).
                $live = new PlaybackSession([
                    'user_id' => $student->id,
                    'lesson_id' => $asset->lesson_id,
                    'media_asset_id' => $asset->id,
                    'media_version_id' => $ready->id,
                    'token_hash' => hash('sha256', Str::random(40)),
                    'device_fingerprint' => hash('sha256', 'android-13-chrome'),
                    'ip' => '156.200.10.15',
                    'issued_at' => now()->subMinutes(20),
                    'expires_at' => now()->addHours(4),
                ]);
                $live->tenant_id = $this->tenant->id;
                $live->scope = 'lesson';
                $live->save();

                $revoked = new PlaybackSession([
                    'user_id' => $student->id,
                    'lesson_id' => $asset->lesson_id,
                    'media_asset_id' => $asset->id,
                    'media_version_id' => $ready->id,
                    'token_hash' => hash('sha256', Str::random(40)),
                    'device_fingerprint' => hash('sha256', 'windows-11-edge'),
                    'ip' => '197.54.1.1',
                    'issued_at' => now()->subDays(2),
                    'expires_at' => now()->subDays(2)->addHours(4),
                    'revoked_at' => now()->subDays(2)->addMinutes(30),
                ]);
                $revoked->tenant_id = $this->tenant->id;
                $revoked->scope = 'lesson';
                $revoked->save();
            }
        }

        // A version whose transcode FAILED — the state the retry UI acts on.
        $failed = new MediaVersion([
            'media_asset_id' => $asset->id,
            'version' => 2,
            'provider' => 'bunny',
            'state' => MediaVersionState::Failed->value,
            'host_video_id' => (string) Str::uuid(),
            'error' => 'Transcode failed: source audio stream is corrupt at 00:41:12.',
        ]);
        $failed->tenant_id = $this->tenant->id;
        $failed->save();
    }

    /**
     * The notification delivery trail: a tenant-authored template (overriding the
     * system one) with its translations and authorship, a per-channel delivery log,
     * and a hard failure. Also back-links the legacy `notifications` row to its
     * template, which is the only thing that explains what rendered it.
     */
    private function trailNotificationDelivery(?User $student): void
    {
        $type = NotificationType::query()->where('key', 'lessons.lesson.available')->first();

        if ($type === null || $student === null) {
            return;
        }

        // A TENANT-scoped template: the teacher rewrote the platform default.
        $template = NotificationTemplate::create([
            'notification_type_id' => $type->id,
            'scope' => 'tenant',
            'tenant_id' => $this->tenant->id,
            'channel' => NotificationChannel::Database->value,
            'is_active' => true,
            'created_by' => $this->teacher->id,
            'edited_by' => $this->teacher->id,
        ]);

        foreach ([['ar', 'محاضرة جديدة', 'تم إتاحة محاضرة «:lesson» — ابدأ المشاهدة الآن.'],
            ['en', 'New lecture', 'The lecture ":lesson" is now available.']] as [$lang, $title, $body]) {
            NotificationTemplateTranslation::create([
                'notification_template_id' => $template->id,
                'language' => $lang,
                'title' => $title,
                'body' => $body,
                'created_by' => $this->teacher->id,
                'edited_by' => $this->teacher->id,
            ]);
        }

        // Authorship on the SYSTEM templates the catalog seeder created, so the
        // "who last edited this" column is never blank on the platform-admin screen.
        NotificationTemplate::query()
            ->whereNull('created_by')
            ->update(['created_by' => $this->teacher->id, 'edited_by' => $this->teacher->id]);
        NotificationTemplateTranslation::query()
            ->whereNull('created_by')
            ->update(['created_by' => $this->teacher->id, 'edited_by' => $this->teacher->id]);

        // Delivery log per attempt on the in-app message.
        $message = NotificationMessage::query()->where('user_id', $student->id)->first();

        if ($message !== null) {
            foreach ([['queued', []], ['sent', ['provider' => 'database']], ['read', ['at' => now()->toIso8601String()]]] as [$status, $meta]) {
                NotificationLog::create([
                    'notification_id' => $message->id,
                    'status' => $status,
                    'metadata' => $meta,
                ]);
            }
        }

        // An SMS that could not be delivered — the failure surface.
        $event = NotificationEvent::query()->where('tenant_id', $this->tenant->id)->first();

        if ($event !== null) {
            NotificationFailure::create([
                'notification_event_id' => $event->id,
                'user_id' => $student->id,
                'channel' => NotificationChannel::Sms->value,
                'error_message' => 'SMS gateway rejected the number: SUBSCRIBER_UNREACHABLE.',
            ]);
        }

        // The legacy row now records which template rendered it.
        Notification::query()
            ->where('tenant_id', $this->tenant->id)
            ->whereNull('template_id')
            ->update(['template_id' => $template->id]);
    }

    /**
     * Archived + revoked rows: a soft-deleted exam and comment, a retired platform
     * package, a consumed OTP, a used magic link. All of these are states the UI
     * has a branch for and never reaches on a happy-path seed.
     */
    private function trailArchivedAndRevoked(AcademicYear $year, ?User $s1, ?User $s2): void
    {
        // An archived (soft-deleted) standalone exam.
        $archived = new Exam([
            'title' => 'اختبار تجريبي — مؤرشف',
            'pass_percent' => 50,
            'pass_mode' => ExamPassMode::Percent->value,
            'attempts_allowed' => 1,
            'duration_min' => 20,
            'is_published' => false,
            'show_answers' => false,
            'starts_at' => now()->subMonths(8),
            'ends_at' => now()->subMonths(7),
        ]);
        $archived->tenant_id = $this->tenant->id;
        $archived->academic_year_id = $year->id;
        $archived->type = ExamType::FreeExam->value;
        $archived->grading_mode = ExamGradingMode::Auto->value;
        $archived->total_marks = 10;
        $archived->save();
        $archived->delete();

        // A comment the teacher removed.
        if ($s2 !== null) {
            $lesson = Lesson::query()->where('academic_year_id', $year->id)->orderBy('id')->first();
            if ($lesson !== null) {
                $removed = new Comment([
                    'lesson_id' => $lesson->id,
                    'user_id' => $s2->id,
                    'body' => 'تعليق مخالف تم حذفه بواسطة المعلّم.',
                    'status' => CommentStatus::Closed->value,
                    'is_hidden' => true,
                ]);
                $removed->tenant_id = $this->tenant->id;
                $removed->academic_year_id = $year->id;
                $removed->save();
                $removed->delete();
            }
        }

        // Chain two of the year's lesson quizzes: the second cannot be taken until
        // the first is passed. `depends_on_exam_id` is the exam-level prerequisite
        // (distinct from the section-level ContentDependency seeded earlier).
        $chain = Exam::query()
            ->where('academic_year_id', $year->id)
            ->where('type', ExamType::LessonQuiz->value)
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($chain->count() === 2) {
            $chain[1]->forceFill(['depends_on_exam_id' => $chain[0]->id])->save();
        }

        // A retired platform subscription package.
        $retired = SubscriptionPackage::firstOrCreate(
            ['slug' => 'legacy-basic'],
            [
                'name' => 'الباقة الأساسية (متوقفة)',
                'description' => 'باقة قديمة لم تعد متاحة للاشتراك الجديد.',
                'price_minor' => 19900,
                'currency' => self::CURRENCY,
                'interval' => BillingInterval::Monthly->value,
                'trial_days' => 0,
                'limits' => ['max_students' => 100, 'max_courses' => 3, 'storage_mb' => 5120, 'max_assistants' => 1],
                'is_active' => false,
                'sort_order' => 0,
            ],
        );
        $retired->delete();

        // A consumed OTP (the login code the student actually used).
        OtpCodeModel::create([
            'identifier' => '01200130001',
            'channel' => 'sms',
            'purpose' => OtpPurpose::Login->value,
            'code_hash' => hash('sha256', '654321'),
            'attempts' => 1,
            'expires_at' => now()->subMinutes(50),
            'consumed_at' => now()->subMinutes(55),
        ]);

        // The parent has opened their magic link at least once.
        ParentMagicLink::query()
            ->where('tenant_id', $this->tenant->id)
            ->update(['last_used_at' => now()->subDays(3)]);

        unset($s1);
    }

    /**
     * Grant + grading edge states: an expiring enrollment, a locked access window,
     * decided extension requests, a manually-graded essay attempt with feedback and
     * a corrected file, and section-level access overrides (one live, one revoked).
     */
    private function trailGrantsAndGrading(AcademicYear $year, ?User $s1, ?User $s2, ?User $s3): void
    {
        // A time-boxed grant — `expires_at` is what the "access ends in N days"
        // banner reads; a permanent grant leaves it null.
        Enrollment::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('source', EnrollmentSource::Center->value)
            ->whereNull('expires_at')
            ->limit(3)
            ->update(['expires_at' => now()->addDays(30)]);

        // Fall back to any grant if the seeder produced no center-sourced ones, so
        // the column is never left entirely null.
        if (Enrollment::query()->whereNotNull('expires_at')->doesntExist()) {
            $any = Enrollment::query()->orderBy('id')->limit(3)->pluck('id');
            Enrollment::query()->whereIn('id', $any)->update(['expires_at' => now()->addDays(30)]);
        }

        // A window the student burned through: locked, no extensions left.
        $window = LessonAccessWindow::query()->orderBy('id')->first();

        if ($window !== null) {
            $window->forceFill([
                'locked_at' => now()->subDays(1),
                'extensions_used' => 2,
            ])->save();

            // …and the two decided extension requests that led there.
            foreach ([[ExtensionStatus::Granted, 3], [ExtensionStatus::Denied, 1]] as [$status, $daysAgo]) {
                $req = new LessonExtensionRequest([
                    'access_window_id' => $window->id,
                    'user_id' => $window->user_id,
                    'status' => $status->value,
                    'requested_at' => now()->subDays($daysAgo + 1),
                    'decided_at' => now()->subDays($daysAgo),
                    'decided_by' => $this->teacher->id,
                ]);
                $req->tenant_id = $this->tenant->id;
                $req->academic_year_id = $window->academic_year_id;
                $req->save();
            }
        }

        // A manually-graded essay attempt: teacher feedback + the annotated file
        // handed back to the student.
        $essayAttempt = ExamAttempt::query()
            ->where('status', AttemptStatus::Graded->value)
            ->orderBy('id')
            ->first();

        if ($essayAttempt !== null) {
            $essayAttempt->forceFill([
                'feedback' => 'إجابة جيدة، لكن راجع ترتيب خطوات التنسيق الهرموني وأضف مثالاً عملياً.',
                'corrected_file' => [
                    'storage_key' => 'corrections/'.Str::uuid().'.pdf',
                    'mime' => 'application/pdf',
                    'size_bytes' => 184320,
                    'corrected_by' => $this->teacher->id,
                ],
            ])->save();
        }

        // Section-level access overrides: one live grant on a single PART of a
        // lesson, and one that was later revoked.
        $section = LessonSection::query()
            ->where('academic_year_id', $year->id)
            ->where('type', LessonSectionType::Pdf->value)
            ->orderBy('id')
            ->first();

        if ($section !== null && $s3 !== null) {
            $live = new ContentAccessOverride([
                'user_id' => $s3->id,
                'academic_year_id' => $year->id,
                'lesson_id' => $section->lesson_id,
                'section_id' => $section->id,
                'granted_by' => $this->teacher->id,
                'note' => 'سمحنا له بالخريطة الذهنية فقط قبل الشراء.',
                'granted_at' => now()->subDays(5),
            ]);
            $live->tenant_id = $this->tenant->id;
            $live->save();

            if ($s2 !== null) {
                $revoked = new ContentAccessOverride([
                    'user_id' => $s2->id,
                    'academic_year_id' => $year->id,
                    'lesson_id' => $section->lesson_id,
                    'section_id' => $section->id,
                    'granted_by' => $this->teacher->id,
                    'note' => 'صلاحية مؤقتة انتهت.',
                    'granted_at' => now()->subDays(20),
                    'revoked_at' => now()->subDays(6),
                ]);
                $revoked->tenant_id = $this->tenant->id;
                $revoked->save();
            }
        }

        // Attach the homework file the student handed in to its section, so
        // `attachments.attachable_*` (the polymorphic link) and `duration_sec` are
        // both exercised — an audio answer carries a length.
        $homework = LessonSection::query()
            ->where('academic_year_id', $year->id)
            ->where('type', LessonSectionType::Homework->value)
            ->orderBy('id')
            ->first();

        if ($homework !== null && $s1 !== null) {
            $file = new Attachment([
                'attachable_type' => LessonSection::class,
                'attachable_id' => $homework->id,
                'kind' => 'audio',
                'storage_key' => 'homework/'.Str::uuid().'.m4a',
                'mime' => 'audio/mp4',
                'size_bytes' => 2411724,
                'duration_sec' => 187,
                'uploaded_by' => $s1->id,
            ]);
            $file->tenant_id = $this->tenant->id;
            $file->save();
        }
    }

    /**
     * Platform-level records: the tenant's own DB connection name, subscription
     * lifecycle stamps (trial, renewal metadata) plus a previously-canceled
     * subscription, and the content-targeted coupon bound to a real package.
     */
    private function trailPlatformRecords(): void
    {
        // Dormant column: set to the connection this tenant actually lives on, so
        // if per-tenant databases ever land the value resolves instead of pointing
        // at a connection that was never configured.
        $this->tenant->forceFill(['dedicated_db_connection' => config('database.default')])->save();

        // The live subscription: trial window + the renewal metadata the billing
        // screen shows.
        $active = TenantSubscription::query()
            ->where('tenant_id', $this->tenant->id)
            ->orderByDesc('id')
            ->first();

        if ($active !== null) {
            $active->forceFill([
                'trial_ends_at' => now()->subMonths(9)->addDays(14),
                'meta' => ['auto_renew' => true, 'seats' => 500, 'invoice_email' => 'billing@ahmedtammam.com'],
            ])->save();

            // A previous subscription the academy canceled when it upgraded — the
            // only rows that ever carry `ends_at` / `canceled_at`.
            $starter = SubscriptionPackage::query()->where('slug', 'starter')->first();
            if ($starter !== null) {
                TenantSubscription::create([
                    'tenant_id' => $this->tenant->id,
                    'package_id' => $starter->id,
                    'status' => SubscriptionStatus::Canceled->value,
                    'price_minor' => $starter->price_minor,
                    'currency' => self::CURRENCY,
                    'started_at' => now()->subMonths(14),
                    'trial_ends_at' => now()->subMonths(14)->addDays(14),
                    'renews_at' => now()->subMonths(9),
                    'ends_at' => now()->subMonths(9),
                    'canceled_at' => now()->subMonths(9)->subDays(3),
                    'meta' => ['auto_renew' => false, 'cancel_reason' => 'upgraded_to_pro'],
                ]);
            }
        }

        // A closed academy (soft-deleted): the platform-admin console has a trashed
        // branch, and nothing else in the seed ever produces one. Content-free, so
        // it cannot leak into any tenant-scoped query.
        $closed = Tenant::create([
            'slug' => 'closed-academy',
            'name' => 'أكاديمية مغلقة (أرشيف)',
            'status' => TenantStatus::Suspended->value,
        ]);
        $closed->delete();

        // Bind the content-targeted coupon now that packages exist.
        $package = Package::query()->orderBy('id')->first();

        if ($package !== null) {
            Coupon::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('code', 'PKG10')
                ->update(['target_type' => Coupon::TARGET_PACKAGE, 'target_id' => $package->id]);
        }
    }
}

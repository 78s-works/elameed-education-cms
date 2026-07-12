<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\CourseCategory;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Engagement\Models\Review;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantDomainType;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use Illuminate\Database\Seeder;

/**
 * Seeds 4 fully-populated, independent academies (each = its own teacher
 * identity + branding + landing + students + categories + courses/units/lessons
 * + enrollments + reviews + wallet balances). Idempotent per academy (skips an
 * academy whose courses already exist). All accounts share the password
 * "password". Login with the teacher phone + X-Tenant: <slug>.
 */
class RealAcademiesSeeder extends Seeder
{
    private const FIRST = ['محمد', 'أحمد', 'يوسف', 'عمر', 'مريم', 'سارة', 'ليلى', 'خالد', 'نور', 'حسن'];

    private const LAST = ['علي', 'حسن', 'إبراهيم', 'محمود', 'السيد', 'عبدالله', 'فؤاد', 'رمضان', 'مصطفى', 'سليم'];

    private const COMMENTS = [
        'شرح ممتاز وواضح جداً، استفدت كثيراً.',
        'أفضل معلّم درست معه على الإطلاق.',
        'المحتوى منظّم والامتحانات مفيدة جداً.',
        'تحسّنت درجاتي بشكل ملحوظ بعد الاشتراك.',
        'أسلوب بسيط وسهل الفهم، أنصح به بشدة.',
    ];

    private int $studentPhone = 1;

    public function run(): void
    {
        foreach ($this->academies() as $n => $spec) {
            $this->buildAcademy($n + 1, $spec);
        }

        $this->command?->info('Seeded 4 academies (password for every account: "password").');
        foreach ($this->academies() as $spec) {
            $this->command?->info("  • {$spec['name']} — X-Tenant: {$spec['slug']} — teacher {$spec['teacher_phone']}");
        }
    }

    private function buildAcademy(int $n, array $spec): void
    {
        $tenant = Tenant::firstOrCreate(['slug' => $spec['slug']], ['name' => $spec['name'], 'status' => TenantStatus::Active]);
        app(TenantContext::class)->setTenant($tenant);
        $this->backfillProfiles($tenant->id); // give already-seeded students their registration fields

        TenantDomain::firstOrCreate(
            ['host' => $spec['slug'].'.'.config('tenancy.base_domain', 'elameed.app')],
            ['tenant_id' => $tenant->id, 'type' => TenantDomainType::Subdomain, 'is_primary' => true],
        );

        $teacher = User::firstOrCreate(
            ['phone' => $spec['teacher_phone']],
            ['name' => $spec['teacher'], 'email' => $spec['slug'].'@academy.test', 'password' => 'password', 'locale' => 'ar', 'phone_verified_at' => now()],
        );
        $tenant->forceFill(['owner_user_id' => $teacher->id])->save();
        TenantUser::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $teacher->id, 'role' => TenantUserRole::Teacher->value],
            ['status' => MembershipStatus::Active->value, 'joined_at' => now()],
        );

        if (! TeacherProfile::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
            $profile = new TeacherProfile([
                'logo_url' => "https://picsum.photos/seed/{$spec['slug']}-logo/160/160",
                'cover_url' => "https://picsum.photos/seed/{$spec['slug']}-cover/1200/400",
                'primary_color' => $spec['primary'],
                'secondary_color' => $spec['secondary'],
                'bio' => "أكاديمية {$spec['subject']} بإشراف {$spec['teacher']}",
                'contact' => ['phone' => $spec['teacher_phone'], 'email' => $spec['slug'].'@academy.test', 'whatsapp' => $spec['teacher_phone'], 'address' => 'القاهرة، مصر'],
                'socials' => ['youtube' => "https://youtube.com/@{$spec['slug']}", 'facebook' => "https://facebook.com/{$spec['slug']}"],
                'layout' => $spec['layout'],
                'landing_sections' => $this->landing($spec),
            ]);
            $profile->tenant_id = $tenant->id;
            $profile->save();
        }

        // Idempotent: don't duplicate content on a re-run.
        if (Course::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
            app(TenantContext::class)->forget();

            return;
        }

        $categories = [];
        foreach ($spec['categories'] as $c) {
            $cat = new CourseCategory(['name' => $c['name'], 'grade' => $c['grade']]);
            $cat->tenant_id = $tenant->id;
            $cat->save();
            $categories[] = $cat;
        }

        $courses = [];
        foreach ($spec['courses'] as $i => $c) {
            $courses[] = $this->makeCourse($tenant->id, $spec['subject'], $c, $categories[$i % count($categories)]->id);
        }

        $students = [];
        for ($s = 1; $s <= 8; $s++) {
            $students[] = $this->makeStudent($tenant->id, $spec['slug'], $n, $s);
        }

        $enroll = app(EnrollmentService::class);
        $ledger = app(LedgerService::class);

        foreach ($students as $si => $student) {
            $picks = [$courses[$si % count($courses)], $courses[($si + 1) % count($courses)]];
            foreach ($picks as $ci => $course) {
                $enroll->grantCourse($tenant->id, $student->id, $course, EnrollmentSource::Manual);

                if (($si + $ci) % 2 === 0) {
                    $review = new Review([
                        'course_id' => $course->id, 'user_id' => $student->id,
                        'rating' => $si % 3 === 0 ? 4 : 5,
                        'comment' => self::COMMENTS[($si + $ci) % count(self::COMMENTS)],
                    ]);
                    $review->tenant_id = $tenant->id;
                    $review->save();
                }
            }

            // Top up every other student's wallet (balanced against teacher_earnings).
            if ($si % 2 === 0) {
                $amount = 5000 + $si * 1500;
                $wallet = $ledger->walletFor($tenant->id, $student->id);
                $ledger->post($tenant->id, "seed:topup:{$tenant->id}:{$student->id}", [
                    ['account' => LedgerEntry::STUDENT_WALLET, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => $amount, 'wallet_id' => $wallet->id],
                    ['account' => LedgerEntry::TEACHER_EARNINGS, 'direction' => LedgerEntry::DEBIT, 'amount_minor' => $amount, 'wallet_id' => null],
                ], 'seed', $student->id);
            }
        }

        app(TenantContext::class)->forget();
    }

    private function makeCourse(int $tenantId, string $subject, array $c, int $categoryId): Course
    {
        $slug = Course::makeUniqueSlug($c['title']);

        $course = new Course([
            'title' => $c['title'],
            'subtitle' => "{$c['title']} — شرح مبسّط ومكثّف",
            'description' => "كورس {$c['title']} في {$subject} يغطّي المنهج كاملاً مع حل نماذج الامتحانات والمراجعات النهائية.",
            'learning_outcomes' => ["إتقان أساسيات {$c['title']}", 'حل المسائل بثقة', 'الاستعداد الكامل للامتحان'],
            'requirements' => ["الإلمام بأساسيات {$subject}", 'دفتر وقلم والتزام أسبوعي'],
            'audience' => ["طلاب الثانوية الراغبون في التفوق في {$subject}"],
            'parts' => [
                ['title' => 'المفاهيم الأساسية', 'lessons_count' => 3, 'duration_min' => 45],
                ['title' => 'التطبيقات وحل المسائل', 'lessons_count' => 3, 'duration_min' => 60],
            ],
            'category_id' => $categoryId,
            'price_minor' => $c['price'],
            'currency' => 'EGP',
            'access_days' => 180,
            'visibility' => ContentVisibility::Visible->value,
            'is_free' => $c['price'] === 0,
            'purchase_enabled' => true,
            'cover_url' => "https://picsum.photos/seed/{$slug}/640/360",
            'promo_video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'points' => 50,
        ]);
        $course->tenant_id = $tenantId;
        $course->slug = $slug;
        $course->save();

        foreach ([1, 2] as $u) {
            $unit = new Unit(['course_id' => $course->id, 'title' => "الوحدة {$u}", 'sort_order' => $u, 'visibility' => ContentVisibility::Visible->value]);
            $unit->tenant_id = $tenantId;
            $unit->save();

            foreach ([1, 2, 3] as $l) {
                $lesson = new Lesson([
                    'unit_id' => $unit->id, 'course_id' => $course->id,
                    'title' => "الدرس {$l}", 'sort_order' => $l,
                    'duration_sec' => (8 + (($u * 3 + $l) % 12)) * 60,
                    'is_free_preview' => $u === 1 && $l === 1,
                    'visibility' => ContentVisibility::Visible->value,
                ]);
                $lesson->tenant_id = $tenantId;
                $lesson->save();
            }
        }

        return $course;
    }

    private function makeStudent(int $tenantId, string $slug, int $n, int $s): User
    {
        $phone = '012'.str_pad((string) $this->studentPhone++, 8, '0', STR_PAD_LEFT);
        $name = self::FIRST[($n * 3 + $s) % 10].' '.self::LAST[($n * 5 + $s) % 10];

        $user = User::firstOrCreate(
            ['phone' => $phone],
            ['name' => $name, 'email' => "s{$s}@{$slug}.test", 'password' => 'password', 'locale' => 'ar', 'phone_verified_at' => now()],
        );
        TenantUser::firstOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $user->id, 'role' => TenantUserRole::Student->value],
            ['status' => MembershipStatus::Active->value, 'joined_at' => now()],
        );

        StudentProfile::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $user->id],
            $this->sampleProfile($s),
        );

        return $user;
    }

    /** Backfill registration profiles for students created before this field set existed. */
    private function backfillProfiles(int $tenantId): void
    {
        $userIds = TenantUser::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('role', TenantUserRole::Student->value)
            ->pluck('user_id');

        foreach ($userIds as $i => $uid) {
            StudentProfile::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenantId, 'user_id' => $uid],
                $this->sampleProfile((int) $i + 1),
            );
        }
    }

    /** @return array<string, string> */
    private function sampleProfile(int $i): array
    {
        $gov = ['القاهرة', 'الجيزة', 'الإسكندرية', 'الدقهلية', 'الشرقية', 'المنوفية'];
        $reg = ['وسط البلد', 'المعادي', 'مدينة نصر', 'فيصل', 'سموحة', 'المنصورة'];
        $yr = ['الأول الثانوي', 'الثاني الثانوي', 'الثالث الثانوي'];
        $edu = ['عام', 'أزهري', 'لغات'];

        return [
            'gender' => $i % 2 ? 'ذكر' : 'أنثى',
            'governorate' => $gov[$i % 6],
            'region' => $reg[$i % 6],
            'academic_year' => $yr[$i % 3],
            'education_type' => $edu[$i % 3],
            'guardian_phone' => '011'.str_pad((string) (20000000 + $i), 8, '0', STR_PAD_LEFT),
        ];
    }

    private function landing(array $spec): array
    {
        $t = $spec['teacher'];
        $subj = $spec['subject'];
        $slug = $spec['slug'];

        return [
            ['key' => 'hero', 'type' => 'hero', 'visible' => true, 'order' => 1, 'content' => [
                'eyebrow' => "أفضل معلّم {$subj}",
                'title_html' => "أتقن {$subj} مع <span>{$t}</span>",
                'description' => 'شرح مبسّط، تدريبات مكثّفة، ومتابعة مستمرة حتى الامتحان.',
                'note' => 'دفعة جديدة كل شهر.',
                'primary_cta' => ['label' => 'ابدأ الآن'], 'secondary_cta' => ['label' => 'تصفّح الكورسات'],
                'teacher' => ['name' => $t, 'role' => "{$subj} — الثانوية", 'image_url' => "https://picsum.photos/seed/{$slug}-teacher/400/400",
                    'card_stats' => [['value' => '12k+', 'label' => 'طالب'], ['value' => '4.9', 'label' => 'تقييم']]],
                'chips' => [['text' => 'دعم مباشر', 'type' => 'green'], ['text' => 'مقاعد محدودة', 'type' => 'red']],
            ]],
            ['key' => 'stats', 'type' => 'stats', 'visible' => true, 'order' => 2, 'content' => ['items' => [
                ['value' => '12,000+', 'label' => 'طالب'], ['value' => '95%', 'label' => 'نسبة نجاح'], ['value' => '120', 'label' => 'درس'],
            ]]],
            ['key' => 'features', 'type' => 'features', 'visible' => true, 'order' => 3, 'content' => ['title' => 'لماذا أكاديميتي', 'subtitle' => 'كل ما تحتاجه للتفوق', 'items' => [
                ['icon' => 'fa-video', 'title' => 'فيديوهات HD', 'desc' => 'مشفّرة وبعلامة مائية.'],
                ['icon' => 'fa-file', 'title' => 'مذكرات', 'desc' => 'ملفات PDF لكل درس.'],
                ['icon' => 'fa-headset', 'title' => 'دعم', 'desc' => 'إجابة على كل أسئلتك.'],
            ]]],
            ['key' => 'about', 'type' => 'about', 'visible' => true, 'order' => 4, 'content' => [
                'badge' => 'منذ 2015', 'title' => "نبذة عن {$t}", 'body' => "خبرة أكثر من 10 سنوات في تدريس {$subj} بنتائج متميزة.",
                'image_url' => "https://picsum.photos/seed/{$slug}-about/600/400", 'points' => ['تركيز على الامتحان', 'اختبارات أسبوعية', 'تقارير لأولياء الأمور'],
            ]],
            ['key' => 'courses', 'type' => 'courses', 'visible' => true, 'order' => 5, 'content' => ['title' => 'الكورسات', 'subtitle' => 'اختر من أين تبدأ'],
                'config' => ['source' => 'featured', 'category_id' => null, 'course_ids' => [], 'limit' => 6]],
            ['key' => 'how', 'type' => 'steps', 'visible' => true, 'order' => 6, 'content' => ['title' => 'كيف تبدأ', 'subtitle' => 'ثلاث خطوات', 'items' => [
                ['n' => '1', 'title' => 'أنشئ حساب', 'desc' => 'بالهاتف.'],
                ['n' => '2', 'title' => 'اشترك', 'desc' => 'بالمحفظة أو البطاقة.'],
                ['n' => '3', 'title' => 'تعلّم', 'desc' => 'في أي وقت وأي مكان.'],
            ]]],
            ['key' => 'testimonials', 'type' => 'testimonials', 'visible' => true, 'order' => 7, 'content' => ['title' => 'آراء الطلاب', 'subtitle' => 'تقييمات حقيقية'],
                'config' => ['source' => 'top_rated', 'min_rating' => 4, 'limit' => 6]],
            ['key' => 'packages', 'type' => 'packages', 'visible' => false, 'order' => 8, 'content' => ['title' => 'الباقات', 'subtitle' => 'وفّر مع الاشتراك', 'items' => [
                ['id' => 1, 'name' => 'شهري', 'badge' => null, 'price' => ['amount_minor' => 20000, 'currency' => 'EGP'], 'period' => 'شهر', 'featured' => false, 'features' => ['كل الكورسات', 'إلغاء متى شئت']],
            ]]],
            ['key' => 'cta', 'type' => 'cta', 'visible' => true, 'order' => 9, 'content' => ['title' => 'جاهز للبدء؟', 'subtitle' => 'انضم اليوم', 'cta' => ['label' => 'اشترك الآن']]],
            ['key' => 'contact', 'type' => 'contact', 'visible' => true, 'order' => 10, 'content' => ['title' => 'تواصل معنا', 'subtitle' => 'نرد خلال يوم']],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function academies(): array
    {
        return [
            [
                'slug' => 'ahmed-physics', 'name' => 'أكاديمية أحمد للفيزياء', 'teacher' => 'أحمد حسن',
                'teacher_phone' => '01500000001', 'primary' => '#1D4ED8', 'secondary' => '#9333EA',
                'layout' => 'classic', 'subject' => 'الفيزياء',
                'categories' => [['name' => 'الصف الثالث الثانوي', 'grade' => 'الثالث الثانوي'], ['name' => 'الصف الثاني الثانوي', 'grade' => 'الثاني الثانوي']],
                'courses' => $this->coursePrices(['الميكانيكا', 'الكهرباء والمغناطيسية', 'الموجات والصوت', 'الفيزياء الحديثة']),
            ],
            [
                'slug' => 'mona-math', 'name' => 'أكاديمية منى للرياضيات', 'teacher' => 'منى علي',
                'teacher_phone' => '01500000002', 'primary' => '#7C3AED', 'secondary' => '#DB2777',
                'layout' => 'grid', 'subject' => 'الرياضيات',
                'categories' => [['name' => 'الصف الثالث الثانوي', 'grade' => 'الثالث الثانوي'], ['name' => 'الصف الأول الثانوي', 'grade' => 'الأول الثانوي']],
                'courses' => $this->coursePrices(['الجبر', 'التفاضل', 'التكامل', 'الهندسة الفراغية']),
            ],
            [
                'slug' => 'khaled-chem', 'name' => 'أكاديمية خالد للكيمياء', 'teacher' => 'خالد إبراهيم',
                'teacher_phone' => '01500000003', 'primary' => '#059669', 'secondary' => '#0D9488',
                'layout' => 'spotlight', 'subject' => 'الكيمياء',
                'categories' => [['name' => 'الصف الثالث الثانوي', 'grade' => 'الثالث الثانوي']],
                'courses' => $this->coursePrices(['الكيمياء العضوية', 'الكيمياء غير العضوية', 'الكيمياء الفيزيائية', 'الكيمياء التحليلية']),
            ],
            [
                'slug' => 'sara-english', 'name' => 'أكاديمية سارة للغة الإنجليزية', 'teacher' => 'سارة محمود',
                'teacher_phone' => '01500000004', 'primary' => '#D97706', 'secondary' => '#DC2626',
                'layout' => 'classic', 'subject' => 'اللغة الإنجليزية',
                'categories' => [['name' => 'الثانوية العامة', 'grade' => 'الثانوية العامة']],
                'courses' => $this->coursePrices(['القواعد Grammar', 'المفردات Vocabulary', 'الكتابة Writing', 'المحادثة Speaking']),
            ],
        ];
    }

    /** Assign a price to each title (the 3rd course of each academy is free). */
    private function coursePrices(array $titles): array
    {
        $prices = [30000, 35000, 0, 40000];

        return array_map(fn ($title, $i) => ['title' => $title, 'price' => $prices[$i] ?? 30000], $titles, array_keys($titles));
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Billing\Models\SubscriptionPackage;
use App\Modules\Billing\Services\SubscriptionService;
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
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantDomainType;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Tenancy\Support\LandingSchema;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use Illuminate\Database\Seeder;

/**
 * The single project seeder. Idempotent — safe to re-run (`php artisan db:seed`).
 * Seeds:
 *   1. the platform administrator (operates the SaaS from the admin host),
 *   2. the teacher subscription packages (M03),
 *   3. two independent demo academies — each a tenant with its own teacher,
 *      students, branding + landing, catalogue (courses → units → lessons),
 *      enrollments, reviews, wallet balances, and an assigned subscription — so
 *      the full stack (tenancy, auth, catalog, commerce, engagement, billing)
 *      and multi-tenant isolation are exercised end to end.
 *
 * Every seeded account uses the password "password". Log in with the account's
 * phone; for a tenant account send the `X-Tenant: <slug>` header (dev) or use
 * the `<slug>.<base_domain>` host.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPlatformAdmin();
        $packages = $this->seedPackages();

        $this->seedAcademy(1, $packages['growth'], [
            'slug' => 'ahmed-physics',
            'name' => 'أكاديمية أحمد للفيزياء',
            'teacher' => 'أحمد حسن',
            'teacher_phone' => '01500000001',
            'subject' => 'الفيزياء',
            'primary' => '#1D4ED8',
            'secondary' => '#9333EA',
            'layout' => 'classic',
            'grade' => 'الثالث الثانوي',
            'courses' => [['الميكانيكا', 30000], ['الكهرباء والمغناطيسية', 35000], ['الفيزياء الحديثة', 0]],
        ]);

        $this->seedAcademy(2, $packages['scale'], [
            'slug' => 'mona-math',
            'name' => 'أكاديمية منى للرياضيات',
            'teacher' => 'منى علي',
            'teacher_phone' => '01500000002',
            'subject' => 'الرياضيات',
            'primary' => '#7C3AED',
            'secondary' => '#DB2777',
            'layout' => 'grid',
            'grade' => 'الثالث الثانوي',
            'courses' => [['الجبر', 30000], ['التفاضل والتكامل', 40000], ['الهندسة الفراغية', 0]],
        ]);

        $this->command?->info('Seeded platform admin, 3 subscription packages, and 2 demo academies. Password for every account: "password".');
    }

    private function seedPlatformAdmin(): void
    {
        // Operates the whole SaaS. Logs in on the admin host (no tenant) and
        // provisions teachers + plans via /admin/*.
        User::firstOrCreate(
            ['phone' => '01000000009'],
            [
                'name' => 'Platform Admin',
                'email' => 'admin@elameed.test',
                'password' => 'password',
                'phone_verified_at' => now(),
                'is_platform_admin' => true,
            ],
        );
    }

    /**
     * Default teacher subscription plans (M03). Indicative EGP minor units.
     *
     * @return array<string, SubscriptionPackage> keyed by slug
     */
    private function seedPackages(): array
    {
        $plans = [
            ['slug' => 'starter', 'name' => 'Starter', 'description' => 'For a teacher just getting started.', 'price_minor' => 0, 'interval' => 'monthly', 'trial_days' => 0, 'sort_order' => 1, 'limits' => ['max_students' => 100, 'max_courses' => 3, 'storage_mb' => 5000, 'max_assistants' => 0]],
            ['slug' => 'growth', 'name' => 'Growth', 'description' => 'For a growing academy with multiple courses.', 'price_minor' => 150000, 'interval' => 'monthly', 'trial_days' => 14, 'sort_order' => 2, 'limits' => ['max_students' => 2000, 'max_courses' => 30, 'storage_mb' => 50000, 'max_assistants' => 3]],
            ['slug' => 'scale', 'name' => 'Scale', 'description' => 'Unlimited students and courses for large academies.', 'price_minor' => 500000, 'interval' => 'monthly', 'trial_days' => 14, 'sort_order' => 3, 'limits' => ['max_students' => null, 'max_courses' => null, 'storage_mb' => 500000, 'max_assistants' => 10]],
        ];

        $packages = [];
        foreach ($plans as $plan) {
            $packages[$plan['slug']] = SubscriptionPackage::updateOrCreate(['slug' => $plan['slug']], $plan);
        }

        return $packages;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function seedAcademy(int $n, SubscriptionPackage $package, array $spec): void
    {
        $tenant = Tenant::firstOrCreate(['slug' => $spec['slug']], ['name' => $spec['name'], 'status' => TenantStatus::Active]);
        app(TenantContext::class)->setTenant($tenant);

        TenantDomain::firstOrCreate(
            ['host' => $spec['slug'].'.'.config('tenancy.base_domain', 'elameed.app')],
            ['tenant_id' => $tenant->id, 'type' => TenantDomainType::Subdomain->value, 'is_primary' => true],
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

        // Assign the subscription plan (M03) only when the tenant has none yet.
        if ($tenant->package_id === null) {
            app(SubscriptionService::class)->assign($tenant, $package);
        }

        if (! TeacherProfile::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
            $profile = new TeacherProfile([
                'logo_url' => "https://picsum.photos/seed/{$spec['slug']}-logo/160/160",
                'cover_url' => "https://picsum.photos/seed/{$spec['slug']}-cover/1200/400",
                'primary_color' => $spec['primary'],
                'secondary_color' => $spec['secondary'],
                'bio' => "أكاديمية {$spec['subject']} بإشراف {$spec['teacher']}",
                'contact' => ['phone' => $spec['teacher_phone'], 'email' => $spec['slug'].'@academy.test', 'address' => 'القاهرة، مصر'],
                'socials' => ['youtube' => "https://youtube.com/@{$spec['slug']}"],
                'layout' => $spec['layout'],
                'landing_sections' => LandingSchema::defaults(),
            ]);
            $profile->tenant_id = $tenant->id;
            $profile->save();
        }

        // Idempotent: don't duplicate the catalogue/students on a re-run.
        if (Course::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
            app(TenantContext::class)->forget();

            return;
        }

        $category = new CourseCategory(['name' => $spec['grade'], 'grade' => $spec['grade']]);
        $category->tenant_id = $tenant->id;
        $category->save();

        $courses = [];
        foreach ($spec['courses'] as [$title, $price]) {
            $courses[] = $this->makeCourse($tenant->id, $spec['subject'], $title, $price, $category->id);
        }

        $enroll = app(EnrollmentService::class);
        $ledger = app(LedgerService::class);

        for ($s = 1; $s <= 6; $s++) {
            $student = $this->makeStudent($n, $tenant->id, $spec['slug'], $s);

            $picks = [$courses[$s % count($courses)], $courses[($s + 1) % count($courses)]];
            foreach ($picks as $ci => $course) {
                $enroll->grantCourse($tenant->id, $student->id, $course, EnrollmentSource::Manual);

                if (($s + $ci) % 2 === 0) {
                    $review = new Review([
                        'course_id' => $course->id,
                        'user_id' => $student->id,
                        'rating' => $s % 3 === 0 ? 4 : 5,
                        'comment' => 'شرح ممتاز وواضح جداً، استفدت كثيراً.',
                    ]);
                    $review->tenant_id = $tenant->id;
                    $review->save();
                }
            }

            // Top up every other student's wallet (balanced against teacher_earnings).
            if ($s % 2 === 0) {
                $amount = 5000 + $s * 1500;
                $wallet = $ledger->walletFor($tenant->id, $student->id);
                $ledger->post($tenant->id, "seed:topup:{$tenant->id}:{$student->id}", [
                    ['account' => LedgerEntry::STUDENT_WALLET, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => $amount, 'wallet_id' => $wallet->id],
                    ['account' => LedgerEntry::TEACHER_EARNINGS, 'direction' => LedgerEntry::DEBIT, 'amount_minor' => $amount, 'wallet_id' => null],
                ], 'seed', $student->id);
            }
        }

        app(TenantContext::class)->forget();
    }

    private function makeCourse(int $tenantId, string $subject, string $title, int $price, int $categoryId): Course
    {
        $slug = Course::makeUniqueSlug($title);

        $course = new Course([
            'title' => $title,
            'subtitle' => "{$title} — شرح مبسّط ومكثّف",
            'description' => "كورس {$title} في {$subject} يغطّي المنهج كاملاً مع حل نماذج الامتحانات.",
            'category_id' => $categoryId,
            'price_minor' => $price,
            'currency' => 'EGP',
            'access_days' => 180,
            'visibility' => ContentVisibility::Visible->value,
            'is_free' => $price === 0,
            'purchase_enabled' => true,
            'cover_url' => "https://picsum.photos/seed/{$slug}/640/360",
            'points' => 50,
        ]);
        $course->tenant_id = $tenantId;
        $course->slug = $slug;
        $course->save();

        foreach ([1, 2] as $u) {
            $unit = new Unit(['course_id' => $course->id, 'title' => "الوحدة {$u}", 'sort_order' => $u, 'visibility' => ContentVisibility::Visible->value]);
            $unit->tenant_id = $tenantId;
            $unit->save();

            foreach ([1, 2] as $l) {
                $lesson = new Lesson([
                    'unit_id' => $unit->id,
                    'course_id' => $course->id,
                    'title' => "الدرس {$l}",
                    'sort_order' => $l,
                    'duration_sec' => (8 + $l) * 60,
                    'is_free_preview' => $u === 1 && $l === 1,
                    'visibility' => ContentVisibility::Visible->value,
                ]);
                $lesson->tenant_id = $tenantId;
                $lesson->save();
            }
        }

        return $course;
    }

    private function makeStudent(int $n, int $tenantId, string $slug, int $s): User
    {
        // Deterministic per (academy, index) so partial re-runs never collide.
        $phone = '0128'.$n.str_pad((string) $s, 6, '0', STR_PAD_LEFT);

        $user = User::firstOrCreate(
            ['phone' => $phone],
            ['name' => "طالب {$s} - {$slug}", 'email' => "s{$s}@{$slug}.test", 'password' => 'password', 'locale' => 'ar', 'phone_verified_at' => now()],
        );
        TenantUser::firstOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $user->id, 'role' => TenantUserRole::Student->value],
            ['status' => MembershipStatus::Active->value, 'joined_at' => now()],
        );

        return $user;
    }
}

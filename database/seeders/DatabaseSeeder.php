<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Enums\LessonSectionType;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Models\PackageType;
use App\Modules\Catalog\Services\AcademicYearContext;
use App\Modules\Catalog\Services\PackageItemService;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lean, ACADEMIC-YEAR-DEPENDENT seed: one academy whose whole catalogue is
 * partitioned by academic year (grade). Each year gets its own package-type,
 * lessons, a package, and students PINNED to that year — so the year-scoping
 * (X-Academic-Year for the teacher, profile-pinned for students) has real data
 * to exercise on every screen.
 *
 * Everything a year owns is created with AcademicYearContext set to that year,
 * so the BelongsToAcademicYear trait stamps `academic_year_id` automatically and
 * nothing leaks across years.
 *
 * Credentials (all password `password`):
 *   - platform admin  admin@elameed.app / 01000000000
 *   - teacher         0101000001  (tenant `farag-physics`)
 *   - students        0101000<year><seq>  e.g. 0101000101 (year 1, online),
 *                     0101000102 (year 1, center), 0101000201 (year 2, online)…
 *
 * Re-runnable: the admin is upserted; the academy is skipped if it already
 * exists (run `php artisan migrate:fresh --seed` for a clean rebuild).
 */
class DatabaseSeeder extends Seeder
{
    private const TENANT_SLUG = 'farag-physics';

    /** The academy's grades (academic years), in display order. */
    private const YEARS = ['الأول الثانوي', 'الثاني الثانوي', 'الثالث الثانوي'];

    public function run(): void
    {
        $this->seedPlatformAdmin();

        // Global notification catalog (types/templates/translations) — needed before
        // any tenant dispatches notifications.
        $this->call(NotificationCatalogSeeder::class);

        if (Tenant::query()->where('slug', self::TENANT_SLUG)->exists()) {
            $this->command?->info('Academy already seeded — skipping content.');
        } else {
            DB::transaction(fn () => $this->seedAcademy());
            $this->command?->info('Seeded platform admin + academy `'.self::TENANT_SLUG.'` across '.count(self::YEARS).' academic years.');
        }

        // The full real-world tenant modelled on ahmedtammam.com (idempotent).
        $this->call(AhmedTammamAcademySeeder::class);
    }

    private function seedPlatformAdmin(): void
    {
        $admin = User::firstOrNew(['email' => 'admin@elameed.app']);
        $admin->forceFill([
            'name' => 'إدارة منصة العميد',
            'phone' => '01000000000',
            'password' => 'password',
            'locale' => 'ar',
            'is_platform_admin' => true,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'remember_token' => $admin->remember_token ?? Str::random(10),
        ])->save();
    }

    private function seedAcademy(): void
    {
        $tenantContext = app(TenantContext::class);
        $yearContext = app(AcademicYearContext::class);

        $tenant = Tenant::create([
            'slug' => self::TENANT_SLUG,
            'name' => 'أكاديمية الأستاذ محمود فرّاج للفيزياء',
            'status' => TenantStatus::Active->value,
        ]);
        $tenantContext->setTenant($tenant);

        $this->makeUser('0101000001', 'الأستاذ محمود فرّاج', $tenant, TenantUserRole::Teacher);
        $profile = new TeacherProfile([
            'login_enabled' => true,
            'registration_enabled' => true,
            'registration_verification_mode' => 'auto',
        ]);
        $profile->tenant_id = $tenant->id;
        $profile->save();

        foreach (self::YEARS as $index => $yearName) {
            $year = new AcademicYear(['name' => $yearName, 'sort_order' => $index]);
            $year->tenant_id = $tenant->id;
            $year->save();

            // Every row created below is stamped with this year by the trait.
            $yearContext->set($year->id);
            $this->seedYear($tenant, $year->id, $index);
        }

        $yearContext->forget();
        $tenantContext->forget();
    }

    /** One year's catalogue + its pinned students. */
    private function seedYear(Tenant $tenant, int $yearId, int $index): void
    {
        $type = new PackageType(['name' => 'اشتراكات شهرية', 'channel' => 'hybrid', 'buy_alone' => false]);
        $type->tenant_id = $tenant->id;
        $type->save();

        // Three lessons spanning the channels so online/center visibility is real.
        $channels = ['online', 'center', 'both'];
        $lessons = [];
        foreach ($channels as $c => $mode) {
            $lesson = new Lesson([
                'title' => "الدرس {$c}+1 — ".self::YEARS[$index],
                'access_mode' => $mode,
                'price_minor' => 5000,
                'is_purchasable' => true,
                'visibility' => ContentVisibility::Visible->value,
            ]);
            $lesson->tenant_id = $tenant->id;
            $lesson->save();

            $section = new LessonSection([
                'lesson_id' => $lesson->id,
                'type' => LessonSectionType::Video->value,
                'access_mode' => $mode,
                'title' => 'مقدمة',
                'sort_order' => 1,
                'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'is_required' => true,
            ]);
            $section->tenant_id = $tenant->id;
            $section->save();

            $lessons[] = $lesson;
        }

        // A package (hybrid, direct-buy) bundling the online + hybrid lessons.
        $package = new Package([
            'name' => 'باقة '.self::YEARS[$index],
            'package_type_id' => $type->id,
            'access_mode' => 'both',
            'price_minor' => 20000,
            'currency' => 'EGP',
            'is_purchasable' => true,
        ]);
        $package->tenant_id = $tenant->id;
        $package->save();

        $items = app(PackageItemService::class);
        $items->attach($package, 'lesson', $lessons[0]->id);
        $items->attach($package, 'lesson', $lessons[2]->id);

        // Two students PINNED to this year: an online one who owns a lesson + the
        // package (so /me/lessons + /me/packages populate), and a center one.
        // Phones: 0101000<year><seq> — e.g. 0101000101 (y1 online), 0101000102 (y1 center).
        $n = $index + 1;
        $online = $this->makeStudent($tenant, $yearId, sprintf('0101000%d01', $n), 'طالب أونلاين '.$n, 'online');
        $this->makeStudent($tenant, $yearId, sprintf('0101000%d02', $n), 'طالب سنتر '.$n, 'center');

        $enroll = app(EnrollmentService::class);
        $enroll->grantLesson($tenant->id, $online->id, $lessons[0], EnrollmentSource::Purchase);
        $enroll->grantPackage($tenant->id, $online->id, $package, EnrollmentSource::Purchase);
    }

    private function makeUser(string $phone, string $name, Tenant $tenant, TenantUserRole $role): User
    {
        $user = User::create([
            'name' => $name,
            'phone' => $phone,
            'email' => $phone.'@example.com',
            'password' => 'password',
            'locale' => 'ar',
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
        ]);
        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function makeStudent(Tenant $tenant, int $yearId, string $phone, string $name, string $studyMode): User
    {
        $user = $this->makeUser($phone, $name, $tenant, TenantUserRole::Student);

        $profile = new StudentProfile([
            'academic_year_id' => $yearId,
            'academic_year' => AcademicYear::find($yearId)?->name,
            'study_mode' => $studyMode,
        ]);
        $profile->tenant_id = $tenant->id;
        $profile->user_id = $user->id;
        $profile->save();

        return $user;
    }
}

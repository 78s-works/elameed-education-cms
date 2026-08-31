<?php

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Models\PackageItem;
use App\Modules\Catalog\Services\LessonAvailabilityService;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The access window is a MATERIAL TERM OF SALE, so it must reach the buy screen
 * with the quote — before the student pays — and it must be the SAME window the
 * enforcement then applies. These tests pin both halves: the payload, and the
 * equality between what was quoted and what `LessonAvailabilityService` stamps.
 */
class CheckoutAccessTermsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function student(): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => TenantUserRole::Student->value, 'status' => MembershipStatus::Active->value,
        ]);

        return $user;
    }

    private function year(): AcademicYear
    {
        $year = new AcademicYear(['name' => '2025 / 2026', 'sort_order' => 0]);
        $year->tenant_id = $this->tenant->id;
        $year->save();

        return $year;
    }

    private function lesson(array $attrs = []): Lesson
    {
        $lesson = new Lesson(array_merge([
            'title' => 'Paid Lesson',
            'price_minor' => 15000,
            'visibility' => ContentVisibility::Visible->value,
            'is_purchasable' => true,
            'access_mode' => 'both',
        ], $attrs));
        $lesson->tenant_id = $this->tenant->id;
        $lesson->academic_year_id = $this->year()->id;
        $lesson->save();

        return $lesson;
    }

    public function test_quote_states_the_lessons_window_before_payment(): void
    {
        $lesson = $this->lesson([
            'availability_days' => 30,
            'extension_hours' => 24,
            'max_extensions' => 2,
            'self_reopen_limit' => 1,
        ]);
        Sanctum::actingAs($this->student());

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->postJson('/api/v1/checkout/quote', [
                'items' => [['type' => 'lesson', 'lesson' => $lesson->id]],
            ])
            ->assertOk()
            ->assertJsonPath('data.lines.0.access_terms.kind', 'lesson')
            ->assertJsonPath('data.lines.0.access_terms.windowed', true)
            ->assertJsonPath('data.lines.0.access_terms.days', 30)
            ->assertJsonPath('data.lines.0.access_terms.extension_hours', 24)
            ->assertJsonPath('data.lines.0.access_terms.max_extensions', 2)
            ->assertJsonPath('data.lines.0.access_terms.self_reopen_limit', 1)
            // The clock starts on first open — NOT at purchase. A buy screen that
            // promises "30 days from purchase" would be disclosing terms the
            // server never enforces.
            ->assertJsonPath('data.lines.0.access_terms.starts_on', 'first_open');
    }

    public function test_the_quoted_window_is_the_window_actually_enforced(): void
    {
        $lesson = $this->lesson(['availability_days' => 30]);
        $student = $this->student();
        Sanctum::actingAs($student);

        $quotedDays = $this->withHeaders(['X-Tenant' => 'demo'])
            ->postJson('/api/v1/checkout/quote', [
                'items' => [['type' => 'lesson', 'lesson' => $lesson->id]],
            ])
            ->assertOk()
            ->json('data.lines.0.access_terms.days');

        // What enforcement does when the student later opens the lesson.
        $window = app(LessonAvailabilityService::class)
            ->start($this->tenant->id, $student->id, $lesson);

        $this->assertNotNull($window);
        $this->assertSame(
            $quotedDays,
            (int) $window->started_at->diffInDays($window->expires_at),
            'The window granted must be exactly the one quoted on the buy screen.',
        );
    }

    public function test_an_unlimited_lesson_is_stated_as_unlimited_not_left_blank(): void
    {
        $lesson = $this->lesson(['availability_days' => null]);
        Sanctum::actingAs($this->student());

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->postJson('/api/v1/checkout/quote', [
                'items' => [['type' => 'lesson', 'lesson' => $lesson->id]],
            ])
            ->assertOk()
            ->assertJsonPath('data.lines.0.access_terms.windowed', false)
            // No duration is invented for a lesson that never locks.
            ->assertJsonPath('data.lines.0.access_terms.days', null);
    }

    public function test_a_package_line_states_the_per_lesson_spread_and_sequential_unlock(): void
    {
        $year = $this->year();
        $mkLesson = function (?int $days) use ($year): Lesson {
            $lesson = new Lesson(['title' => 'L', 'access_mode' => 'both', 'availability_days' => $days]);
            $lesson->tenant_id = $this->tenant->id;
            $lesson->academic_year_id = $year->id;
            $lesson->save();

            return $lesson;
        };
        $package = new Package([
            'name' => 'Term 1', 'access_mode' => 'both',
            'price_minor' => 50000, 'currency' => 'EGP', 'is_purchasable' => true,
        ]);
        $package->tenant_id = $this->tenant->id;
        $package->academic_year_id = $year->id;
        $package->save();

        foreach ([7, 14, null] as $i => $days) {
            $item = new PackageItem([
                'package_id' => $package->id, 'item_type' => PackageItem::TYPE_LESSON,
                'item_id' => $mkLesson($days)->id, 'sort_order' => $i,
            ]);
            $item->tenant_id = $this->tenant->id;
            $item->save();
        }

        Sanctum::actingAs($this->student());

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->postJson('/api/v1/checkout/quote', [
                'items' => [['type' => 'package', 'package' => $package->uuid]],
            ])
            ->assertOk()
            ->assertJsonPath('data.lines.0.access_terms.kind', 'package')
            ->assertJsonPath('data.lines.0.access_terms.lessons_count', 3)
            ->assertJsonPath('data.lines.0.access_terms.windowed_lessons', 2)
            ->assertJsonPath('data.lines.0.access_terms.unlimited_lessons', 1)
            ->assertJsonPath('data.lines.0.access_terms.min_days', 7)
            ->assertJsonPath('data.lines.0.access_terms.max_days', 14)
            // A package has no clock of its own: the first lesson opens at
            // purchase, the rest as the student completes the one before.
            ->assertJsonPath('data.lines.0.access_terms.starts_on', 'purchase_then_sequential');
    }

    public function test_a_wallet_topup_carries_no_access_terms(): void
    {
        Sanctum::actingAs($this->student());

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->postJson('/api/v1/checkout/quote', [
                'items' => [['type' => 'wallet_topup', 'amount_minor' => 10000]],
            ])
            ->assertOk()
            ->assertJsonPath('data.lines.0.access_terms', null);
    }

    public function test_the_public_package_tree_states_each_lessons_window(): void
    {
        $year = $this->year();
        $lesson = new Lesson(['title' => 'L', 'access_mode' => 'both', 'availability_days' => 21, 'is_purchasable' => true]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->academic_year_id = $year->id;
        $lesson->save();

        $package = new Package(['name' => 'Term 1', 'access_mode' => 'both', 'price_minor' => 50000, 'currency' => 'EGP', 'is_purchasable' => true]);
        $package->tenant_id = $this->tenant->id;
        $package->academic_year_id = $year->id;
        $package->save();

        $item = new PackageItem([
            'package_id' => $package->id, 'item_type' => PackageItem::TYPE_LESSON,
            'item_id' => $lesson->id, 'sort_order' => 0,
        ]);
        $item->tenant_id = $this->tenant->id;
        $item->save();

        // Public route — the student reads the terms before signing in, too.
        $this->withHeaders(['X-Tenant' => 'demo'])
            ->getJson("/api/v1/packages/{$package->uuid}")
            ->assertOk()
            ->assertJsonPath('data.items.0.item.access_terms.days', 21)
            ->assertJsonPath('data.items.0.item.access_terms.starts_on', 'first_open');
    }
}

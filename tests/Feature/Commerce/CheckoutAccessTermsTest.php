<?php

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonAccessWindow;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Models\PackageItem;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
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

    /** Simulate a completed top-up so the student can pay from the wallet. */
    private function creditWallet(User $user, int $amount): void
    {
        $ledger = app(LedgerService::class);
        $wallet = $ledger->walletFor($this->tenant->id, $user->id);
        $ledger->post($this->tenant->id, "test-topup:{$user->id}", [
            ['account' => LedgerEntry::GATEWAY_CLEARING, 'direction' => LedgerEntry::DEBIT, 'amount_minor' => $amount],
            ['account' => LedgerEntry::STUDENT_WALLET, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => $amount, 'wallet_id' => $wallet->id],
        ]);
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
            // The clock starts AT PURCHASE: FulfillOrderService -> grantLesson ->
            // LessonAvailabilityService::start. A buy screen that promised "starts
            // when you first open it" would be disclosing terms the server does not
            // enforce — see the paid end-to-end test below, which proves it.
            ->assertJsonPath('data.lines.0.access_terms.starts_on', 'purchase');
    }

    /**
     * The one test that actually protects the acceptance criterion: quote, then PAY,
     * then read the window fulfilment created. An earlier version called
     * LessonAvailabilityService::start() directly on an unbought lesson, which only
     * re-checked addDays arithmetic — and that is exactly why a wrong `starts_on`
     * ("first open") survived a green suite.
     */
    public function test_the_quoted_window_is_the_window_the_purchase_actually_grants(): void
    {
        $lesson = $this->lesson(['availability_days' => 30]);
        $student = $this->student();
        $this->creditWallet($student, 20000);
        Sanctum::actingAs($student);
        $h = ['X-Tenant' => 'demo'];
        $cart = ['items' => [['type' => 'lesson', 'lesson' => $lesson->id]]];

        $quoted = $this->withHeaders($h)->postJson('/api/v1/checkout/quote', $cart)
            ->assertOk()->json('data.lines.0.access_terms');

        // No window before the sale.
        $this->assertNull(
            LessonAccessWindow::withoutGlobalScopes()
                ->where('user_id', $student->id)->where('lesson_id', $lesson->id)->first(),
        );

        $orderUuid = $this->withHeaders($h)->postJson('/api/v1/checkout/order', $cart)
            ->assertStatus(201)->json('data.uuid');
        $this->withHeaders($h)->postJson('/api/v1/checkout/pay', [
            'order' => $orderUuid, 'method' => 'wallet',
        ])->assertOk()->assertJsonPath('data.status', 'paid');

        // PAYMENT opened it — nobody opened the lesson. This is what makes
        // starts_on = 'purchase' the honest value.
        $window = LessonAccessWindow::withoutGlobalScopes()
            ->where('user_id', $student->id)->where('lesson_id', $lesson->id)->first();

        $this->assertNotNull($window, 'Paying for a windowed lesson must open its window.');
        $this->assertSame('purchase', $quoted['starts_on']);
        $this->assertSame(
            $quoted['days'],
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

    /**
     * The null-safety of the package fold, which the mixed-case test alone did not
     * cover: an empty package, an all-unlimited one, and an all-windowed one that
     * shares a single duration.
     */
    public function test_package_terms_are_null_safe_for_the_degenerate_shapes(): void
    {
        $year = $this->year();
        $mkPackage = function () use ($year): Package {
            $pkg = new Package([
                'name' => 'P', 'access_mode' => 'both',
                'price_minor' => 1000, 'currency' => 'EGP', 'is_purchasable' => true,
            ]);
            $pkg->tenant_id = $this->tenant->id;
            $pkg->academic_year_id = $year->id;
            $pkg->save();

            return $pkg;
        };
        $addLesson = function (Package $pkg, ?int $days, int $order): void {
            $lesson = new Lesson(['title' => 'L', 'access_mode' => 'both', 'availability_days' => $days]);
            $lesson->tenant_id = $this->tenant->id;
            $lesson->academic_year_id = $pkg->academic_year_id;
            $lesson->save();
            $item = new PackageItem([
                'package_id' => $pkg->id, 'item_type' => PackageItem::TYPE_LESSON,
                'item_id' => $lesson->id, 'sort_order' => $order,
            ]);
            $item->tenant_id = $this->tenant->id;
            $item->save();
        };

        $empty = $mkPackage();
        $allUnlimited = $mkPackage();
        $addLesson($allUnlimited, null, 0);
        $addLesson($allUnlimited, null, 1);
        $sameWindow = $mkPackage();
        $addLesson($sameWindow, 7, 0);
        $addLesson($sameWindow, 7, 1);

        Sanctum::actingAs($this->student());
        $h = ['X-Tenant' => 'demo'];
        $quote = fn (Package $p) => $this->withHeaders($h)
            ->postJson('/api/v1/checkout/quote', ['items' => [['type' => 'package', 'package' => $p->uuid]]])
            ->assertOk()->json('data.lines.0.access_terms');

        $e = $quote($empty);
        $this->assertSame(0, $e['lessons_count']);
        $this->assertNull($e['min_days'], 'An empty package must not invent a duration.');
        $this->assertNull($e['max_days']);

        $u = $quote($allUnlimited);
        $this->assertSame(2, $u['lessons_count']);
        $this->assertSame(0, $u['windowed_lessons']);
        $this->assertSame(2, $u['unlimited_lessons']);
        $this->assertNull($u['min_days']);
        $this->assertNull($u['max_days']);

        $w = $quote($sameWindow);
        $this->assertSame(2, $w['windowed_lessons']);
        $this->assertSame(0, $w['unlimited_lessons']);
        // Equal bounds — the client prints one number instead of a range.
        $this->assertSame(7, $w['min_days']);
        $this->assertSame(7, $w['max_days']);
    }

    public function test_a_sub_package_row_states_its_own_terms(): void
    {
        $year = $this->year();
        $lesson = new Lesson(['title' => 'L', 'access_mode' => 'both', 'availability_days' => 9]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->academic_year_id = $year->id;
        $lesson->save();

        $mk = function (?int $price) use ($year): Package {
            $pkg = new Package([
                'name' => 'P', 'access_mode' => 'both',
                'price_minor' => $price, 'currency' => 'EGP', 'is_purchasable' => $price !== null,
            ]);
            $pkg->tenant_id = $this->tenant->id;
            $pkg->academic_year_id = $year->id;
            $pkg->save();

            return $pkg;
        };
        $parent = $mk(50000);
        $child = $mk(null);
        foreach ([[$parent, PackageItem::TYPE_PACKAGE, $child->id], [$child, PackageItem::TYPE_LESSON, $lesson->id]] as [$owner, $type, $id]) {
            $item = new PackageItem(['package_id' => $owner->id, 'item_type' => $type, 'item_id' => $id, 'sort_order' => 0]);
            $item->tenant_id = $this->tenant->id;
            $item->save();
        }

        // A nested package row used to come back with no terms at all, so the buy
        // tree showed a blank beside every sub-package.
        $this->withHeaders(['X-Tenant' => 'demo'])
            ->getJson("/api/v1/packages/{$parent->uuid}")
            ->assertOk()
            ->assertJsonPath('data.items.0.item.type', 'package')
            ->assertJsonPath('data.items.0.item.access_terms.kind', 'package')
            ->assertJsonPath('data.items.0.item.access_terms.lessons_count', 1)
            ->assertJsonPath('data.items.0.item.access_terms.min_days', 9);
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

    public function test_the_package_resource_folds_lessons_nested_in_sub_packages(): void
    {
        // The package detail page used to fold this client-side from the DIRECT
        // item rows only, so a structural package whose lessons live in
        // sub-packages showed a blank buy card and a mixed one quoted too narrow
        // a range. The server fold is recursive (descendantLessonIds), which is
        // also what the checkout line and SequentialUnlockService walk.
        $year = $this->year();
        $mkLesson = function (?int $days) use ($year): Lesson {
            $lesson = new Lesson(['title' => 'L', 'access_mode' => 'both', 'availability_days' => $days]);
            $lesson->tenant_id = $this->tenant->id;
            $lesson->academic_year_id = $year->id;
            $lesson->save();

            return $lesson;
        };
        $mkPackage = function (?int $price) use ($year): Package {
            $pkg = new Package([
                'name' => 'P', 'access_mode' => 'both',
                'price_minor' => $price, 'currency' => 'EGP', 'is_purchasable' => $price !== null,
            ]);
            $pkg->tenant_id = $this->tenant->id;
            $pkg->academic_year_id = $year->id;
            $pkg->save();

            return $pkg;
        };
        $attach = function (Package $pkg, string $type, int $id, int $order = 0): void {
            $item = new PackageItem([
                'package_id' => $pkg->id, 'item_type' => $type, 'item_id' => $id, 'sort_order' => $order,
            ]);
            $item->tenant_id = $this->tenant->id;
            $item->save();
        };

        // parent -> [ 7-day lesson, child -> [ 30-day lesson, unlimited lesson ] ]
        $parent = $mkPackage(50000);
        $child = $mkPackage(null);
        $attach($parent, PackageItem::TYPE_LESSON, $mkLesson(7)->id, 0);
        $attach($parent, PackageItem::TYPE_PACKAGE, $child->id, 1);
        $attach($child, PackageItem::TYPE_LESSON, $mkLesson(30)->id, 0);
        $attach($child, PackageItem::TYPE_LESSON, $mkLesson(null)->id, 1);

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->getJson("/api/v1/packages/{$parent->uuid}")
            ->assertOk()
            // 3 descendant lessons, not the 1 direct row a client-side fold saw.
            ->assertJsonPath('data.access_terms.lessons_count', 3)
            ->assertJsonPath('data.access_terms.windowed_lessons', 2)
            ->assertJsonPath('data.access_terms.unlimited_lessons', 1)
            ->assertJsonPath('data.access_terms.min_days', 7)
            ->assertJsonPath('data.access_terms.max_days', 30);
    }

    public function test_a_structural_package_with_no_direct_lessons_still_states_its_terms(): void
    {
        $year = $this->year();
        $lesson = new Lesson(['title' => 'L', 'access_mode' => 'both', 'availability_days' => 21]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->academic_year_id = $year->id;
        $lesson->save();

        $parent = new Package(['name' => 'Structural', 'access_mode' => 'both', 'price_minor' => 9000, 'currency' => 'EGP', 'is_purchasable' => true]);
        $parent->tenant_id = $this->tenant->id;
        $parent->academic_year_id = $year->id;
        $parent->save();
        $child = new Package(['name' => 'Chapter', 'access_mode' => 'both', 'price_minor' => null, 'currency' => 'EGP', 'is_purchasable' => false]);
        $child->tenant_id = $this->tenant->id;
        $child->academic_year_id = $year->id;
        $child->save();

        foreach ([[$parent, PackageItem::TYPE_PACKAGE, $child->id], [$child, PackageItem::TYPE_LESSON, $lesson->id]] as [$owner, $type, $id]) {
            $item = new PackageItem(['package_id' => $owner->id, 'item_type' => $type, 'item_id' => $id, 'sort_order' => 0]);
            $item->tenant_id = $this->tenant->id;
            $item->save();
        }

        // Every lesson sits one level down; the buy card must NOT come back blank.
        $this->withHeaders(['X-Tenant' => 'demo'])
            ->getJson("/api/v1/packages/{$parent->uuid}")
            ->assertOk()
            ->assertJsonPath('data.access_terms.lessons_count', 1)
            ->assertJsonPath('data.access_terms.min_days', 21)
            ->assertJsonPath('data.access_terms.max_days', 21);
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
            ->assertJsonPath('data.items.0.item.access_terms.starts_on', 'purchase');
    }
}

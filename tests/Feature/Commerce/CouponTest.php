<?php

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Commerce\Models\Coupon;
use App\Modules\Commerce\Models\Order;
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
 * Coupons & promo codes (M21): teacher CRUD, quote/order discounting, ledger
 * balance with a discount, usage-limit + validity enforcement, content-target
 * (lesson/package) scoping, and tenant isolation of codes.
 */
class CouponTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function member(TenantUserRole $role): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        return $user;
    }

    private function lesson(int $priceMinor): Lesson
    {
        $year = AcademicYear::where('tenant_id', $this->tenant->id)->orderBy('id')->first();
        if ($year === null) {
            $year = new AcademicYear(['name' => 'Default', 'sort_order' => 0]);
            $year->tenant_id = $this->tenant->id;
            $year->save();
        }

        $lesson = new Lesson([
            'title' => 'Lecture', 'price_minor' => $priceMinor, 'is_purchasable' => true,
            'visibility' => ContentVisibility::Visible->value,
        ]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->academic_year_id = $year->id;
        $lesson->save();

        return $lesson;
    }

    private function coupon(array $attrs, ?Tenant $tenant = null): Coupon
    {
        $tenant ??= $this->tenant;
        $coupon = new Coupon(array_merge(['code' => 'SAVE20', 'type' => 'percent', 'value' => 20], $attrs));
        $coupon->tenant_id = $tenant->id;
        $coupon->save();

        return $coupon;
    }

    private function creditWallet(User $user, int $amount): void
    {
        $ledger = app(LedgerService::class);
        $wallet = $ledger->walletFor($this->tenant->id, $user->id);
        $ledger->post($this->tenant->id, "test-topup:{$user->id}", [
            ['account' => LedgerEntry::GATEWAY_CLEARING, 'direction' => LedgerEntry::DEBIT, 'amount_minor' => $amount],
            ['account' => LedgerEntry::STUDENT_WALLET, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => $amount, 'wallet_id' => $wallet->id],
        ]);
    }

    public function test_teacher_can_create_and_list_coupons(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $h = ['X-Tenant' => 'demo'];

        $this->withHeaders($h)->postJson('/api/v1/teacher/coupons', [
            'code' => 'WELCOME', 'type' => 'percent', 'value' => 25, 'usage_limit' => 100,
        ])->assertStatus(201)
            ->assertJsonPath('data.code', 'WELCOME')
            ->assertJsonPath('data.type', 'percent')
            ->assertJsonPath('data.value', 25);

        $this->withHeaders($h)->getJson('/api/v1/teacher/coupons')->assertOk()->assertJsonPath('data.0.code', 'WELCOME');
    }

    public function test_percentage_over_100_is_rejected(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/teacher/coupons', [
            'code' => 'BAD', 'type' => 'percent', 'value' => 150,
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
    }

    public function test_percent_coupon_discounts_quote_and_order(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $lesson = $this->lesson(15000);
        $this->coupon(['code' => 'SAVE20', 'value' => 20]);
        Sanctum::actingAs($student);
        $h = ['X-Tenant' => 'demo'];
        $cart = ['items' => [['type' => 'lesson', 'lesson' => $lesson->id]], 'coupon' => 'SAVE20'];

        $this->withHeaders($h)->postJson('/api/v1/checkout/quote', $cart)
            ->assertOk()
            ->assertJsonPath('data.subtotal_minor', 15000)
            ->assertJsonPath('data.discount_minor', 3000)
            ->assertJsonPath('data.total_minor', 12000)
            ->assertJsonPath('data.coupon', 'SAVE20');

        $this->withHeaders($h)->postJson('/api/v1/checkout/order', $cart)
            ->assertStatus(201)
            ->assertJsonPath('data.discount_minor', 3000)
            ->assertJsonPath('data.total_minor', 12000)
            ->assertJsonPath('data.coupon', 'SAVE20');
    }

    public function test_wallet_purchase_with_coupon_balances_ledger_and_counts_redemption(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $lesson = $this->lesson(15000);
        $coupon = $this->coupon(['code' => 'SAVE20', 'value' => 20, 'usage_limit' => 5]);
        $this->creditWallet($student, 20000);
        Sanctum::actingAs($student);
        $h = ['X-Tenant' => 'demo'];
        $cart = ['items' => [['type' => 'lesson', 'lesson' => $lesson->id]], 'coupon' => 'SAVE20'];

        $orderUuid = $this->withHeaders($h)->postJson('/api/v1/checkout/order', $cart)->json('data.uuid');
        $this->withHeaders($h)->postJson('/api/v1/checkout/pay', ['order' => $orderUuid, 'method' => 'wallet'])
            ->assertOk()->assertJsonPath('data.status', 'paid');

        // Wallet charged the discounted total: 20000 - 12000 = 8000.
        $wallet = app(LedgerService::class)->walletFor($this->tenant->id, $student->id);
        $this->assertSame(8000, app(LedgerService::class)->balance($wallet));

        // Ledger balances overall.
        $debits = (int) LedgerEntry::withoutGlobalScopes()->where('direction', 'debit')->sum('amount_minor');
        $credits = (int) LedgerEntry::withoutGlobalScopes()->where('direction', 'credit')->sum('amount_minor');
        $this->assertSame($debits, $credits);

        // Redemption counted exactly once.
        $this->assertSame(1, (int) $coupon->fresh()->used_count);
    }

    public function test_invalid_and_used_up_coupons_are_rejected(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $lesson = $this->lesson(15000);
        Sanctum::actingAs($student);
        $h = ['X-Tenant' => 'demo'];

        // Unknown code.
        $this->withHeaders($h)->postJson('/api/v1/checkout/quote', [
            'items' => [['type' => 'lesson', 'lesson' => $lesson->id]], 'coupon' => 'NOPE',
        ])->assertStatus(422);

        // Used-up code.
        $this->coupon(['code' => 'MAXED', 'value' => 10, 'usage_limit' => 1])->forceFill(['used_count' => 1])->save();
        $this->withHeaders($h)->postJson('/api/v1/checkout/quote', [
            'items' => [['type' => 'lesson', 'lesson' => $lesson->id]], 'coupon' => 'MAXED',
        ])->assertStatus(422);
    }

    public function test_target_scoped_coupon_only_applies_to_that_lesson(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $scoped = $this->lesson(10000);
        $other = $this->lesson(10000);
        $this->coupon(['code' => 'PHYS', 'value' => 50, 'target_type' => 'lesson', 'target_id' => $scoped->id]);
        Sanctum::actingAs($student);
        $h = ['X-Tenant' => 'demo'];

        // Applies to the scoped lesson.
        $this->withHeaders($h)->postJson('/api/v1/checkout/quote', [
            'items' => [['type' => 'lesson', 'lesson' => $scoped->id]], 'coupon' => 'PHYS',
        ])->assertOk()->assertJsonPath('data.discount_minor', 5000);

        // Not to a different lesson.
        $this->withHeaders($h)->postJson('/api/v1/checkout/quote', [
            'items' => [['type' => 'lesson', 'lesson' => $other->id]], 'coupon' => 'PHYS',
        ])->assertStatus(422);
    }

    public function test_teacher_can_scope_coupon_to_a_lesson(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $lesson = $this->lesson(10000);

        $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/teacher/coupons', [
            'code' => 'LEC', 'type' => 'percent', 'value' => 10,
            'target_type' => 'lesson', 'target_id' => $lesson->id,
        ])->assertStatus(201)
            ->assertJsonPath('data.target_type', 'lesson')
            ->assertJsonPath('data.target_id', $lesson->id);
    }

    public function test_coupon_codes_are_tenant_isolated(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $this->coupon(['code' => 'DEMOONLY', 'value' => 20], $other); // belongs to the OTHER tenant

        $student = $this->member(TenantUserRole::Student); // demo student
        $lesson = $this->lesson(15000);
        Sanctum::actingAs($student);

        // The other tenant's code must not resolve in `demo`.
        $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/checkout/quote', [
            'items' => [['type' => 'lesson', 'lesson' => $lesson->id]], 'coupon' => 'DEMOONLY',
        ])->assertStatus(422);
    }
}

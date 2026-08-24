<?php

namespace Tests\Feature\Reporting;

use App\Models\User;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
use App\Modules\Centers\Models\ActivationCode;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Modules\Commerce\Enums\OrderStatus;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Commerce\Models\Payment;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Commerce\Services\RefundService;
use App\Modules\Engagement\Models\Attachment;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Wallet\Models\PaymentReceipt;
use App\Modules\Wallet\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GrantsTenantRoles;
use Tests\TestCase;

/**
 * The sales ledger (M17): every transaction in one list, with the payment method
 * normalized out of `payments.gateway` / `enrollments.source` / a zero price,
 * totals that follow the active filter, and wallet top-ups kept out of the
 * revenue figure.
 */
class SalesLedgerTest extends TestCase
{
    use GrantsTenantRoles;
    use RefreshDatabase;

    private Tenant $tenant;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        $this->year = $this->academicYear();
    }

    public function test_ledger_needs_its_own_permission(): void
    {
        $viewer = $this->member(TenantUserRole::Assistant, ['reports.view']);
        Sanctum::actingAs($viewer);

        $this->tenantGet('/api/v1/teacher/sales')->assertStatus(403);

        $this->grantPermissions($viewer, $this->tenant, ['finance.sales.view'], 'Sales reader');
        Sanctum::actingAs($viewer);

        $this->tenantGet('/api/v1/teacher/sales')->assertStatus(200);
    }

    public function test_rows_normalize_the_payment_method_across_sources(): void
    {
        $lesson = $this->lesson('Algebra', 20000);
        $package = $this->package('Term 1', 50000);

        $cardBuyer = $this->student('Card Buyer', '01000000001');
        $walletBuyer = $this->student('Wallet Buyer', '01000000002');
        $codeStudent = $this->student('Code Student', '01000000003');
        $centerStudent = $this->student('Center Student', '01000000004');
        $freeStudent = $this->student('Free Student', '01000000005');

        $this->order($cardBuyer, [[$lesson, 20000]], OrderStatus::Paid, 'paymob');
        $this->order($walletBuyer, [[$lesson, 20000]], OrderStatus::Paid, null);
        $this->order($freeStudent, [[$lesson, 0]], OrderStatus::Paid, null);
        // A pure top-up checkout is money in the wallet, not a sale: no row.
        $this->topupOrder($walletBuyer, 100000);

        $this->grant($codeStudent, $package, EnrollmentSource::Code);
        $this->grant($centerStudent, $lesson, EnrollmentSource::Center);

        $response = $this->asLedgerReader()->tenantGet('/api/v1/teacher/sales')->assertStatus(200);

        $methods = collect($response->json('data'))
            ->mapWithKeys(fn (array $row): array => [$row['student']['name'] => $row['method']])
            ->all();

        $this->assertSame([
            'Card Buyer' => 'card_paymob',
            'Wallet Buyer' => 'wallet',
            'Free Student' => 'free',
            'Code Student' => 'code',
            'Center Student' => 'center',
        ], collect([
            'Card Buyer', 'Wallet Buyer', 'Free Student', 'Code Student', 'Center Student',
        ])->mapWithKeys(fn (string $name): array => [$name => $methods[$name] ?? null])->all());

        // 5 sales rows; the top-up order contributed none.
        $this->assertSame(5, $response->json('meta.total'));

        // 20000 + 20000 + 0 + 50000 (package price) + 20000 (lesson price) = 110000.
        $this->assertSame(110000, $response->json('totals.net_sales_minor'));
        $this->assertSame(0, $response->json('totals.refunded_minor'));
    }

    public function test_a_package_grant_folds_its_fan_out_into_one_row(): void
    {
        $package = $this->package('Term 1', 50000);
        $student = $this->student('Grant Student', '01000000010');

        // Three per-lesson rows from one package grant — one sale, not three.
        foreach (['A', 'B', 'C'] as $title) {
            $this->grantRow($student, $this->lesson($title, 10000), EnrollmentSource::Manual, $package);
        }

        $response = $this->asLedgerReader()->tenantGet('/api/v1/teacher/sales')->assertStatus(200);

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('manual', $response->json('data.0.method'));
        $this->assertSame(50000, $response->json('totals.net_sales_minor'));
    }

    public function test_pending_and_failed_appear_in_the_table_but_not_in_the_total(): void
    {
        $lesson = $this->lesson('Algebra', 20000);
        $this->order($this->student('Paid', '01000000021'), [[$lesson, 20000]], OrderStatus::Paid, 'paymob');
        $this->order($this->student('Pending', '01000000022'), [[$lesson, 20000]], OrderStatus::Pending, 'paymob');
        $this->order($this->student('Failed', '01000000023'), [[$lesson, 20000]], OrderStatus::Failed, 'paymob');

        $response = $this->asLedgerReader()->tenantGet('/api/v1/teacher/sales')->assertStatus(200);

        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(20000, $response->json('totals.net_sales_minor'));
        $this->assertSame(20000, $response->json('totals.pending_minor'));
        $this->assertSame(20000, $response->json('totals.failed_minor'));

        $failedOnly = $this->asLedgerReader()
            ->tenantGet('/api/v1/teacher/sales?status[]=failed')
            ->assertStatus(200);

        $this->assertSame(1, $failedOnly->json('meta.total'));
        $this->assertSame(0, $failedOnly->json('totals.net_sales_minor'));
    }

    public function test_a_refund_shows_in_the_table_and_comes_off_the_total(): void
    {
        $lesson = $this->lesson('Algebra', 20000);
        $student = $this->student('Refunded', '01000000031');
        $order = $this->order($student, [[$lesson, 20000]], OrderStatus::Paid, 'paymob');
        app(EnrollmentService::class)
            ->grantLesson($this->tenant->id, $student->id, $lesson, EnrollmentSource::Purchase);

        app(RefundService::class)->refund($order->fresh(), null, 'Duplicate charge');

        $response = $this->asLedgerReader()->tenantGet('/api/v1/teacher/sales')->assertStatus(200);

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('refunded', $response->json('data.0.status'));
        $this->assertSame(20000, $response->json('data.0.refunded_minor'));
        // Collected then given back: nothing left in the sales figure.
        $this->assertSame(20000, $response->json('totals.collected_minor'));
        $this->assertSame(20000, $response->json('totals.refunded_minor'));
        $this->assertSame(0, $response->json('totals.net_sales_minor'));

        // A full refund takes back the access it paid for, and the money lands in
        // the student's wallet.
        $this->assertSame(EnrollmentStatus::Cancelled, Enrollment::withoutGlobalScopes()
            ->where('user_id', $student->id)->where('lesson_id', $lesson->id)->first()?->status);
        $ledger = app(LedgerService::class);
        $this->assertSame(20000, $ledger->balance($ledger->walletFor($this->tenant->id, $student->id)));
    }

    public function test_a_partial_refund_subtracts_only_its_own_amount(): void
    {
        $lesson = $this->lesson('Algebra', 20000);
        $student = $this->student('Partial', '01000000032');
        $order = $this->order($student, [[$lesson, 20000]], OrderStatus::Paid, 'paymob');

        app(RefundService::class)->refund($order->fresh(), 5000, 'Goodwill');

        $response = $this->asLedgerReader()->tenantGet('/api/v1/teacher/sales')->assertStatus(200);

        // Access survives a price correction, so the order is still `paid`.
        $this->assertSame('paid', $response->json('data.0.status'));
        $this->assertSame(5000, $response->json('data.0.refunded_minor'));
        $this->assertSame(15000, $response->json('totals.net_sales_minor'));
        // The rest stays refundable.
        $this->assertSame(15000, app(RefundService::class)->refundable($order->fresh()));
    }

    public function test_refund_endpoint_is_gated_and_records_the_refund(): void
    {
        $lesson = $this->lesson('Algebra', 20000);
        $student = $this->student('Buyer', '01000000041');
        $order = $this->order($student, [[$lesson, 20000]], OrderStatus::Paid, 'paymob');

        // Reading the ledger is not permission to move money back.
        $reader = $this->member(TenantUserRole::Assistant, ['finance.sales.view']);
        Sanctum::actingAs($reader);
        $this->tenantPost("/api/v1/teacher/orders/{$order->uuid}/refunds", [])->assertStatus(403);

        $this->grantPermissions($reader, $this->tenant, ['finance.refunds.manage'], 'Refunder');
        Sanctum::actingAs($reader);

        $this->tenantPost("/api/v1/teacher/orders/{$order->uuid}/refunds", ['reason' => 'Wrong lesson'])
            ->assertStatus(201)
            ->assertJsonPath('data.amount_minor', 20000)
            ->assertJsonPath('data.order_status', 'refunded')
            ->assertJsonPath('data.revoked_access', true);

        // Fully refunded: nothing left to refund.
        $this->tenantPost("/api/v1/teacher/orders/{$order->uuid}/refunds", [])->assertStatus(422);
    }

    public function test_filters_narrow_both_the_rows_and_the_totals(): void
    {
        $algebra = $this->lesson('Algebra', 20000);
        $geometry = $this->lesson('Geometry', 30000);

        $a = $this->student('Ahmed Ali', '01011112222');
        $b = $this->student('Basma Nour', '01033334444');

        $old = $this->order($a, [[$algebra, 20000]], OrderStatus::Paid, 'paymob');
        $old->forceFill(['created_at' => now()->subMonths(2)])->save();
        $this->order($b, [[$geometry, 30000]], OrderStatus::Paid, null);

        $reader = $this->asLedgerReader();

        // Date window: only this month's sale.
        $thisMonth = $reader->tenantGet('/api/v1/teacher/sales?date_from='.now()->startOfMonth()->toDateString())
            ->assertStatus(200);
        $this->assertSame(1, $thisMonth->json('meta.total'));
        $this->assertSame(30000, $thisMonth->json('totals.net_sales_minor'));

        // Method multi-select.
        $byMethod = $reader->tenantGet('/api/v1/teacher/sales?method[]=card_paymob')->assertStatus(200);
        $this->assertSame(1, $byMethod->json('meta.total'));
        $this->assertSame(20000, $byMethod->json('totals.net_sales_minor'));

        // One item.
        $byItem = $reader->tenantGet('/api/v1/teacher/sales?item_type=lesson&item_id='.$geometry->id)->assertStatus(200);
        $this->assertSame(1, $byItem->json('meta.total'));
        $this->assertSame('Basma Nour', $byItem->json('data.0.student.name'));

        // One student, addressed by uuid.
        $byStudent = $reader->tenantGet('/api/v1/teacher/sales?student_id='.$a->uuid)->assertStatus(200);
        $this->assertSame(1, $byStudent->json('meta.total'));

        // Search by phone, and by order reference.
        $byPhone = $reader->tenantGet('/api/v1/teacher/sales?q=01033334444')->assertStatus(200);
        $this->assertSame(1, $byPhone->json('meta.total'));

        $byRef = $reader->tenantGet('/api/v1/teacher/sales?q='.$old->uuid)->assertStatus(200);
        $this->assertSame(1, $byRef->json('meta.total'));
        $this->assertSame('Ahmed Ali', $byRef->json('data.0.student.name'));
    }

    public function test_wallet_topups_are_reported_apart_from_sales(): void
    {
        $lesson = $this->lesson('Algebra', 20000);
        $student = $this->student('Topper', '01000000051');

        $this->order($student, [[$lesson, 20000]], OrderStatus::Paid, null);
        $this->topupOrder($student, 100000);
        $this->walletCode($student, 25000);
        $this->approvedReceipt($student, 15000);

        $reader = $this->asLedgerReader();

        $sales = $reader->tenantGet('/api/v1/teacher/sales')->assertStatus(200);
        $this->assertSame(20000, $sales->json('totals.net_sales_minor'));

        $topups = $reader->tenantGet('/api/v1/teacher/sales/topups')->assertStatus(200);
        $this->assertSame(140000, $topups->json('data.total_minor'));
        $this->assertFalse($topups->json('data.counted_in_sales'));
    }

    public function test_export_streams_the_filtered_rows_as_csv(): void
    {
        $lesson = $this->lesson('Algebra', 20000);
        $this->order($this->student('Ahmed Ali', '01000000061'), [[$lesson, 20000]], OrderStatus::Paid, 'paymob');

        $response = $this->asLedgerReader()
            ->tenantGet('/api/v1/teacher/sales/export?format=csv')
            ->assertStatus(200);

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Payment method', $csv);
        $this->assertStringContainsString('Ahmed Ali', $csv);
        $this->assertStringContainsString('Algebra', $csv);
        // Minor units become pounds in the spreadsheet.
        $this->assertStringContainsString('200', $csv);
    }

    public function test_filter_options_list_the_academys_sellable_items(): void
    {
        $this->lesson('Algebra', 20000);
        $this->package('Term 1', 50000);

        $response = $this->asLedgerReader()->tenantGet('/api/v1/teacher/sales/filters')->assertStatus(200);

        $titles = collect($response->json('data.items'))->pluck('title')->all();
        $this->assertContains('Algebra', $titles);
        $this->assertContains('Term 1', $titles);
        $this->assertCount(7, $response->json('data.methods'));
    }

    public function test_a_pending_order_offers_no_refund(): void
    {
        $lesson = $this->lesson('Algebra', 20000);
        $this->order($this->student('Pending', '01000000071'), [[$lesson, 20000]], OrderStatus::Pending, 'paymob');

        $response = $this->asLedgerReader()->tenantGet('/api/v1/teacher/sales')->assertStatus(200);

        // Nothing was collected, so there is nothing to send back.
        $this->assertSame(0, $response->json('data.0.refundable_minor'));
    }

    public function test_the_ledger_follows_the_active_academic_year(): void
    {
        $thisYearLesson = $this->lesson('Algebra', 20000);
        $otherYear = $this->academicYear('Next year');
        $otherYearLesson = $this->lesson('Chemistry', 30000, $otherYear);

        $mine = $this->student('Pinned Here', '01000000081', $this->year);
        $theirs = $this->student('Pinned There', '01000000082', $otherYear);

        $this->order($mine, [[$thisYearLesson, 20000]], OrderStatus::Paid, 'paymob');
        $this->order($theirs, [[$otherYearLesson, 30000]], OrderStatus::Paid, 'paymob');
        $this->grantRow($theirs, $otherYearLesson, EnrollmentSource::Center, null, $otherYear);

        // No year pinned → the whole academy.
        $all = $this->asLedgerReader()->tenantGet('/api/v1/teacher/sales')->assertStatus(200);
        $this->assertSame(3, $all->json('meta.total'));
        $this->assertSame(80000, $all->json('totals.net_sales_minor'));

        // Year pinned → only that year's money, by the grant's own year and by the
        // buyer's pinned year for an order.
        $pinned = $this->asLedgerReader()->yearGet('/api/v1/teacher/sales', $this->year->uuid)->assertStatus(200);
        $this->assertSame(1, $pinned->json('meta.total'));
        $this->assertSame(20000, $pinned->json('totals.net_sales_minor'));
        $this->assertSame('Pinned Here', $pinned->json('data.0.student.name'));
    }

    public function test_a_grant_row_keeps_its_title_across_years(): void
    {
        $otherYear = $this->academicYear('Next year');
        $otherYearLesson = $this->lesson('Chemistry', 30000, $otherYear);
        $student = $this->student('Cross Year', '01000000091', $otherYear);
        $this->grantRow($student, $otherYearLesson, EnrollmentSource::Code, null, $otherYear);

        // Read the ledger with a DIFFERENT year pinned but no year filter on the
        // row set: the title still resolves (it is not scoped away to an em dash).
        $response = $this->asLedgerReader()->tenantGet('/api/v1/teacher/sales')->assertStatus(200);

        $this->assertSame('Chemistry', $response->json('data.0.items.0.title'));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function asLedgerReader(): self
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher, ['finance.sales.view']));

        return $this;
    }

    private function tenantGet(string $uri)
    {
        return $this->withHeaders(['X-Tenant' => 'demo'])->getJson($uri);
    }

    /** Same as {@see tenantGet} with an academic year pinned on the request. */
    private function yearGet(string $uri, string $yearUuid)
    {
        return $this->withHeaders(['X-Tenant' => 'demo', 'X-Academic-Year' => $yearUuid])->getJson($uri);
    }

    private function tenantPost(string $uri, array $payload)
    {
        return $this->withHeaders(['X-Tenant' => 'demo'])->postJson($uri, $payload);
    }

    private function member(TenantUserRole $role, array $permissions = []): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        if ($permissions !== []) {
            $this->grantPermissions($user, $this->tenant, $permissions);
        }

        return $user;
    }

    private function student(string $name, string $phone, ?AcademicYear $year = null): User
    {
        $user = User::factory()->create(['name' => $name, 'phone' => $phone]);
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => TenantUserRole::Student->value, 'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        // The pinned year is what an ORDER is keyed on (orders carry no year).
        $profile = new StudentProfile(['user_id' => $user->id, 'academic_year_id' => ($year ?? $this->year)->id]);
        $profile->tenant_id = $this->tenant->id;
        $profile->save();

        return $user;
    }

    private function academicYear(string $name = 'Default'): AcademicYear
    {
        $year = new AcademicYear(['name' => $name, 'sort_order' => 0]);
        $year->tenant_id = $this->tenant->id;
        $year->save();

        return $year->fresh();
    }

    private function lesson(string $title, int $priceMinor, ?AcademicYear $year = null): Lesson
    {
        $lesson = new Lesson(['title' => $title, 'price_minor' => $priceMinor, 'sort_order' => 0]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->academic_year_id = ($year ?? $this->year)->id;
        $lesson->save();

        return $lesson->fresh();
    }

    private function package(string $name, int $priceMinor): Package
    {
        $package = new Package(['name' => $name, 'price_minor' => $priceMinor, 'is_purchasable' => true]);
        $package->tenant_id = $this->tenant->id;
        $package->academic_year_id = $this->year->id;
        $package->save();

        return $package->fresh();
    }

    /**
     * @param  list<array{0: Lesson, 1: int}>  $items
     * @param  string|null  $gateway  null → paid from the wallet (no gateway row)
     */
    private function order(User $student, array $items, OrderStatus $status, ?string $gateway, int $discount = 0): Order
    {
        $total = array_sum(array_map(fn (array $i): int => $i[1], $items)) - $discount;

        $order = new Order([
            'user_id' => $student->id,
            'subtotal_minor' => $total + $discount,
            'discount_minor' => $discount,
            'total_minor' => max(0, $total),
            'status' => $status->value,
        ]);
        $order->tenant_id = $this->tenant->id;
        $order->save();

        foreach ($items as [$lesson, $price]) {
            $item = new OrderItem([
                'order_id' => $order->id,
                'item_type' => OrderItem::TYPE_LESSON,
                'item_id' => $lesson->id,
                'price_minor' => $price,
                'title' => $lesson->title,
            ]);
            $item->tenant_id = $this->tenant->id;
            $item->save();
        }

        if ($gateway !== null) {
            $payment = new Payment([
                'order_id' => $order->id,
                'gateway' => $gateway,
                'gateway_txn_id' => 'txn-'.uniqid(),
                'amount_minor' => max(0, $total),
                'status' => $status === OrderStatus::Paid ? Payment::STATUS_PAID : Payment::STATUS_PENDING,
            ]);
            $payment->tenant_id = $this->tenant->id;
            $payment->save();
        }

        return $order->fresh();
    }

    /** A pure wallet top-up checkout — money into the wallet, never a sale. */
    private function topupOrder(User $student, int $amountMinor): Order
    {
        $order = new Order([
            'user_id' => $student->id,
            'subtotal_minor' => $amountMinor,
            'total_minor' => $amountMinor,
            'status' => OrderStatus::Paid->value,
        ]);
        $order->tenant_id = $this->tenant->id;
        $order->save();

        $item = new OrderItem([
            'order_id' => $order->id,
            'item_type' => OrderItem::TYPE_WALLET_TOPUP,
            'price_minor' => $amountMinor,
            'title' => 'Top-up',
        ]);
        $item->tenant_id = $this->tenant->id;
        $item->save();

        return $order;
    }

    /** A grant with no order behind it (code / staff / center). */
    private function grant(User $student, Lesson|Package $item, EnrollmentSource $source): void
    {
        if ($item instanceof Package) {
            $this->grantRow($student, $this->lesson('Inside '.$item->name, 10000), $source, $item);
            $code = new ActivationCode([
                'code' => 'CODE-'.strtoupper(uniqid()),
                'type' => 'content',
                'target_type' => 'package',
                'target_id' => $item->id,
                'status' => 'redeemed',
                'redeemed_by' => $student->id,
                'redeemed_at' => now(),
            ]);
            $code->tenant_id = $this->tenant->id;
            $code->save();

            return;
        }

        $this->grantRow($student, $item, $source, null);
    }

    private function grantRow(User $student, Lesson $lesson, EnrollmentSource $source, ?Package $package, ?AcademicYear $year = null): Enrollment
    {
        $enrollment = new Enrollment([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'package_id' => $package?->id,
            'source' => $source->value,
            'status' => EnrollmentStatus::Active->value,
            'academic_year_id' => ($year ?? $this->year)->id,
        ]);
        $enrollment->tenant_id = $this->tenant->id;
        $enrollment->save();

        return $enrollment;
    }

    private function walletCode(User $student, int $amountMinor): void
    {
        $code = new ActivationCode([
            'code' => 'W-'.strtoupper(uniqid()),
            'type' => 'wallet',
            'amount_minor' => $amountMinor,
            'status' => 'redeemed',
            'redeemed_by' => $student->id,
            'redeemed_at' => now(),
        ]);
        $code->tenant_id = $this->tenant->id;
        $code->save();
    }

    private function approvedReceipt(User $student, int $amountMinor): void
    {
        $attachment = new Attachment([
            'kind' => Attachment::KIND_IMAGE,
            'storage_key' => 'attachments/r-'.uniqid().'.png',
            'mime' => 'image/png',
            'size_bytes' => 1024,
            'uploaded_by' => $student->id,
        ]);
        $attachment->tenant_id = $this->tenant->id;
        $attachment->save();

        $receipt = new PaymentReceipt([
            'user_id' => $student->id,
            'method' => 'vodafone_cash',
            'amount_minor' => $amountMinor,
            'attachment_id' => $attachment->id,
            'status' => 'approved',
            'reviewed_at' => now(),
        ]);
        $receipt->tenant_id = $this->tenant->id;
        $receipt->save();
    }
}

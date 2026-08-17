<?php

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Commerce\Models\Invoice;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Invoice PDF rendering + the access-controlled /invoices endpoints (S2).
 */
class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'أكاديمية العميد', 'status' => TenantStatus::Active]);
        $this->h = ['X-Tenant' => 'demo'];
    }

    private function member(Tenant $tenant, TenantUserRole $role, ?string $phone = null): User
    {
        $user = User::factory()->create($phone ? ['phone' => $phone] : []);
        TenantUser::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        return $user;
    }

    private function purchasableLesson(Tenant $tenant): Lesson
    {
        $year = AcademicYear::where('tenant_id', $tenant->id)->orderBy('id')->first();
        if ($year === null) {
            $year = new AcademicYear(['name' => 'Default', 'sort_order' => 0]);
            $year->tenant_id = $tenant->id;
            $year->save();
        }

        $l = new Lesson(['title' => 'Lesson', 'visibility' => ContentVisibility::Visible->value, 'price_minor' => 50000, 'currency' => 'EGP', 'is_purchasable' => true]);
        $l->tenant_id = $tenant->id;
        $l->academic_year_id = $year->id;
        $l->save();

        return $l;
    }

    private function fund(User $user, int $amount): void
    {
        $ledger = app(LedgerService::class);
        $wallet = $ledger->walletFor($this->tenant->id, $user->id);
        $ledger->post($this->tenant->id, 'test:topup:'.$user->id, [
            ['account' => LedgerEntry::STUDENT_WALLET, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => $amount, 'wallet_id' => $wallet->id],
            ['account' => LedgerEntry::TEACHER_EARNINGS, 'direction' => LedgerEntry::DEBIT, 'amount_minor' => $amount, 'wallet_id' => null],
        ], 'seed', $user->id);
    }

    /** Buy a lesson with wallet funds; returns the issued invoice. */
    private function buy(User $student, Lesson $lesson): Invoice
    {
        Sanctum::actingAs($student);
        $order = $this->withHeaders($this->h)->postJson('/api/v1/checkout/order', [
            'items' => [['type' => 'lesson', 'lesson' => $lesson->id]],
        ])->assertStatus(201)->json('data.uuid');

        $this->withHeaders($this->h)->postJson('/api/v1/checkout/pay', ['order' => $order, 'method' => 'wallet'])
            ->assertStatus(200);

        return Invoice::withoutGlobalScopes()->whereHas('order', fn ($q) => $q->where('uuid', $order))->firstOrFail();
    }

    public function test_paying_an_order_generates_a_downloadable_invoice_pdf(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->member($this->tenant, TenantUserRole::Teacher);
        $student = $this->member($this->tenant, TenantUserRole::Student, '01090000001');
        $this->fund($student, 1000000);
        $lesson = $this->purchasableLesson($this->tenant);

        $invoice = $this->buy($student, $lesson);

        // pdf_url populated on fulfillment + file actually on the private disk.
        $this->assertTrue($invoice->fresh()->hasPdf());
        $this->assertTrue(Storage::disk('local')->exists($invoice->fresh()->pdf_url));

        // Buyer downloads a real PDF.
        $res = $this->withHeaders($this->h)->get("/api/v1/invoices/{$invoice->uuid}/download");
        $res->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_buyer_lists_and_reads_own_invoice(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $student = $this->member($this->tenant, TenantUserRole::Student, '01090000002');
        $this->fund($student, 1000000);
        $invoice = $this->buy($student, $this->purchasableLesson($this->tenant));

        $this->withHeaders($this->h)->getJson('/api/v1/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $invoice->uuid)
            ->assertJsonPath('data.0.number', $invoice->number);

        $this->withHeaders($this->h)->getJson("/api/v1/invoices/{$invoice->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $invoice->uuid)
            ->assertJsonPath('data.pdf_available', true)
            ->assertJsonStructure(['data' => ['uuid', 'number', 'download_url', 'order' => ['uuid', 'total_minor']]]);
    }

    public function test_another_student_cannot_access_someone_elses_invoice(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $buyer = $this->member($this->tenant, TenantUserRole::Student, '01090000003');
        $this->fund($buyer, 1000000);
        $invoice = $this->buy($buyer, $this->purchasableLesson($this->tenant));

        $intruder = $this->member($this->tenant, TenantUserRole::Student, '01090000004');
        Sanctum::actingAs($intruder);
        $this->withHeaders($this->h)->getJson("/api/v1/invoices/{$invoice->uuid}")->assertStatus(403);
        $this->withHeaders($this->h)->get("/api/v1/invoices/{$invoice->uuid}/download")->assertStatus(403);

        // And the intruder's own list does not include it.
        $this->withHeaders($this->h)->getJson('/api/v1/invoices')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_teacher_can_download_any_invoice_in_the_tenant(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $teacher = $this->member($this->tenant, TenantUserRole::Teacher);
        $student = $this->member($this->tenant, TenantUserRole::Student, '01090000005');
        $this->fund($student, 1000000);
        $invoice = $this->buy($student, $this->purchasableLesson($this->tenant));

        Sanctum::actingAs($teacher);
        $this->withHeaders($this->h)->get("/api/v1/invoices/{$invoice->uuid}/download")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_cross_tenant_invoice_is_404(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $student = $this->member($this->tenant, TenantUserRole::Student, '01090000006');
        $this->fund($student, 1000000);
        $invoice = $this->buy($student, $this->purchasableLesson($this->tenant));

        // A user in another tenant cannot resolve tenant A's invoice by uuid.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $outsider = $this->member($other, TenantUserRole::Student, '01090000007');

        Sanctum::actingAs($outsider);
        $this->withHeaders(['X-Tenant' => 'other'])->getJson("/api/v1/invoices/{$invoice->uuid}")->assertStatus(404);
    }
}

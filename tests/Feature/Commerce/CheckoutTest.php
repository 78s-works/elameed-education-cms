<?php

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Models\Invoice;
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

class CheckoutTest extends TestCase
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

    private function course(int $priceMinor): Course
    {
        $course = new Course([
            'title' => 'Paid Course', 'price_minor' => $priceMinor,
            'visibility' => ContentVisibility::Visible->value, 'purchase_enabled' => true,
        ]);
        $course->tenant_id = $this->tenant->id;
        $course->slug = 'paid-'.uniqid();
        $course->save();

        return $course;
    }

    private function creditWallet(User $user, int $amount): void
    {
        $ledger = app(LedgerService::class);
        $wallet = $ledger->walletFor($this->tenant->id, $user->id);
        // Simulate a completed top-up.
        $ledger->post($this->tenant->id, "test-topup:{$user->id}", [
            ['account' => LedgerEntry::GATEWAY_CLEARING, 'direction' => LedgerEntry::DEBIT, 'amount_minor' => $amount],
            ['account' => LedgerEntry::STUDENT_WALLET, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => $amount, 'wallet_id' => $wallet->id],
        ]);
    }

    public function test_wallet_purchase_enrolls_student_and_balances_ledger(): void
    {
        $student = $this->student();
        $course = $this->course(15000);
        $this->creditWallet($student, 20000);
        Sanctum::actingAs($student);
        $h = ['X-Tenant' => 'demo'];

        // quote
        $this->withHeaders($h)->postJson('/api/v1/checkout/quote', [
            'items' => [['type' => 'course', 'course' => $course->uuid]],
        ])->assertOk()->assertJsonPath('data.total_minor', 15000);

        // order
        $orderUuid = $this->withHeaders($h)->postJson('/api/v1/checkout/order', [
            'items' => [['type' => 'course', 'course' => $course->uuid]],
        ])->assertStatus(201)->json('data.uuid');

        // pay from wallet
        $this->withHeaders($h)->postJson('/api/v1/checkout/pay', [
            'order' => $orderUuid, 'method' => 'wallet',
        ])->assertOk()->assertJsonPath('data.status', 'paid');

        // Enrollment granted
        $this->assertTrue(
            Enrollment::withoutGlobalScopes()->where('user_id', $student->id)->where('course_id', $course->id)->exists()
        );
        // Invoice issued (number 1 for the tenant)
        $this->assertSame(1, (int) Invoice::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->value('number'));
        // Wallet balance reduced 20000 - 15000 = 5000
        $wallet = app(LedgerService::class)->walletFor($this->tenant->id, $student->id);
        $this->assertSame(5000, app(LedgerService::class)->balance($wallet));

        // Ledger is balanced overall
        $debits = LedgerEntry::withoutGlobalScopes()->where('direction', 'debit')->sum('amount_minor');
        $credits = LedgerEntry::withoutGlobalScopes()->where('direction', 'credit')->sum('amount_minor');
        $this->assertSame((int) $debits, (int) $credits);
    }

    public function test_wallet_purchase_rejected_when_insufficient_balance(): void
    {
        $student = $this->student();
        $course = $this->course(15000);
        Sanctum::actingAs($student);
        $h = ['X-Tenant' => 'demo'];

        $orderUuid = $this->withHeaders($h)->postJson('/api/v1/checkout/order', [
            'items' => [['type' => 'course', 'course' => $course->uuid]],
        ])->json('data.uuid');

        $this->withHeaders($h)->postJson('/api/v1/checkout/pay', [
            'order' => $orderUuid, 'method' => 'wallet',
        ])->assertStatus(422);

        $this->assertFalse(
            Enrollment::withoutGlobalScopes()->where('user_id', $student->id)->exists()
        );
    }

    public function test_paymob_webhook_is_idempotent(): void
    {
        $student = $this->student();
        $course = $this->course(15000);
        Sanctum::actingAs($student);
        $h = ['X-Tenant' => 'demo'];

        $orderUuid = $this->withHeaders($h)->postJson('/api/v1/checkout/order', [
            'items' => [['type' => 'course', 'course' => $course->uuid]],
        ])->json('data.uuid');

        $this->withHeaders($h)->postJson('/api/v1/checkout/pay', [
            'order' => $orderUuid, 'method' => 'paymob',
        ])->assertOk()->assertJsonPath('data.status', 'pending');

        $payload = [
            'transaction_id' => 'TXN-123',
            'order_uuid' => $orderUuid,
            'amount_cents' => 15000,
            'success' => true,
        ];
        $hmac = hash_hmac('sha512', "TXN-123|{$orderUuid}|15000|true", config('commerce.paymob.hmac_secret'));
        $headers = ['X-Paymob-Hmac' => $hmac];

        // First delivery → paid + enrolled
        $this->withHeaders($headers)->postJson('/api/v1/webhooks/paymob', $payload)
            ->assertOk()->assertJsonPath('data.status', 'paid');

        // Replay → already processed, no double enrollment / double ledger
        $this->withHeaders($headers)->postJson('/api/v1/webhooks/paymob', $payload)
            ->assertOk()->assertJsonPath('data.status', 'already_processed');

        $this->assertSame(1, Enrollment::withoutGlobalScopes()->where('user_id', $student->id)->count());
        $this->assertSame(1, LedgerEntry::withoutGlobalScopes()->where('idempotency_key', 'like', '%:teacher_earnings:credit')->count());
    }

    public function test_webhook_rejects_bad_signature(): void
    {
        $student = $this->student();
        $course = $this->course(15000);
        Sanctum::actingAs($student);
        $orderUuid = $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/checkout/order', [
            'items' => [['type' => 'course', 'course' => $course->uuid]],
        ])->json('data.uuid');

        $this->withHeaders(['X-Paymob-Hmac' => 'wrong'])->postJson('/api/v1/webhooks/paymob', [
            'transaction_id' => 'TXN-x', 'order_uuid' => $orderUuid, 'amount_cents' => 15000, 'success' => true,
        ])->assertStatus(400);
    }
}

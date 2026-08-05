<?php

namespace Tests\Feature\Wallet;

use App\Models\User;
use App\Modules\Engagement\Models\Attachment;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Models\PaymentReceipt;
use App\Modules\Wallet\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * VD manual wallet top-ups (R9/R10): student submits a receipt → pending; a teacher
 * or `finance`-assistant approves (credits the wallet once, idempotently) or rejects.
 */
class PaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function member(TenantUserRole $role, array $permissions = [], ?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value,
            'permissions' => $permissions !== [] ? $permissions : null,
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function attachmentFor(User $user, ?Tenant $tenant = null): Attachment
    {
        $tenant ??= $this->tenant;
        $attachment = new Attachment([
            'kind' => Attachment::KIND_IMAGE,
            'storage_key' => 'attachments/receipt-'.uniqid().'.png',
            'mime' => 'image/png',
            'size_bytes' => 2048,
            'uploaded_by' => $user->id,
        ]);
        $attachment->tenant_id = $tenant->id;
        $attachment->save();

        return $attachment;
    }

    /** Submit a manual top-up via the student endpoint; returns the receipt uuid. */
    private function submit(User $student, int $amount = 50000): string
    {
        Sanctum::actingAs($student);
        $attachment = $this->attachmentFor($student);

        return $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/wallet/topup/manual', [
            'method' => 'vodafone_cash',
            'amount_minor' => $amount,
            'attachment_id' => $attachment->uuid,
        ])->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->json('data.uuid');
    }

    private function balanceOf(User $student): int
    {
        $ledger = app(LedgerService::class);

        return $ledger->balance($ledger->walletFor($this->tenant->id, $student->id));
    }

    public function test_student_submit_creates_a_pending_receipt(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $uuid = $this->submit($student, 30000);

        $this->assertDatabaseHas('payment_receipts', [
            'uuid' => $uuid, 'tenant_id' => $this->tenant->id, 'user_id' => $student->id,
            'method' => 'vodafone_cash', 'amount_minor' => 30000, 'status' => 'pending',
        ]);
        // Not credited until approved.
        $this->assertSame(0, $this->balanceOf($student));
    }

    public function test_student_cannot_submit_with_another_users_attachment(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $other = $this->member(TenantUserRole::Student);
        $foreignAttachment = $this->attachmentFor($other);

        Sanctum::actingAs($student);
        $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/wallet/topup/manual', [
            'method' => 'instapay', 'amount_minor' => 10000, 'attachment_id' => $foreignAttachment->uuid,
        ])->assertStatus(422);
    }

    public function test_finance_reviewer_approve_credits_the_wallet_once(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $uuid = $this->submit($student, 50000);

        $reviewer = $this->member(TenantUserRole::Assistant, ['finance']);
        Sanctum::actingAs($reviewer);
        $this->withHeaders(['X-Tenant' => 'demo'])
            ->postJson("/api/v1/teacher/payment-receipts/{$uuid}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(50000, $this->balanceOf($student));

        $receipt = PaymentReceipt::withoutGlobalScopes()->where('uuid', $uuid)->first();
        $this->assertNotNull($receipt->reviewed_by);
        $this->assertNotNull($receipt->reviewed_at);
        $this->assertNotNull($receipt->ledger_entry_id);
        $this->assertSame($reviewer->id, (int) $receipt->reviewed_by);
    }

    public function test_double_approve_returns_409_and_does_not_double_credit(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $uuid = $this->submit($student, 50000);

        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $h = ['X-Tenant' => 'demo'];

        $this->withHeaders($h)->postJson("/api/v1/teacher/payment-receipts/{$uuid}/approve")->assertOk();
        // Second tap → conflict, no state change.
        $this->withHeaders($h)->postJson("/api/v1/teacher/payment-receipts/{$uuid}/approve")->assertStatus(409);

        $this->assertSame(50000, $this->balanceOf($student));

        $creditLegs = LedgerEntry::withoutGlobalScopes()
            ->where('ref_type', 'receipt')
            ->where('account', LedgerEntry::STUDENT_WALLET)
            ->where('direction', LedgerEntry::CREDIT)
            ->count();
        $this->assertSame(1, $creditLegs);
    }

    public function test_reject_stamps_reason_and_does_not_credit(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $uuid = $this->submit($student, 50000);

        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $this->withHeaders(['X-Tenant' => 'demo'])
            ->postJson("/api/v1/teacher/payment-receipts/{$uuid}/reject", ['reason' => 'Blurry image'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.reject_reason', 'Blurry image');

        $this->assertSame(0, $this->balanceOf($student));
        // A rejected receipt cannot then be approved.
        $this->withHeaders(['X-Tenant' => 'demo'])
            ->postJson("/api/v1/teacher/payment-receipts/{$uuid}/approve")
            ->assertStatus(409);
    }

    public function test_assistant_without_finance_permission_is_forbidden(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $uuid = $this->submit($student, 50000);

        Sanctum::actingAs($this->member(TenantUserRole::Assistant, ['students']));
        $h = ['X-Tenant' => 'demo'];

        $this->withHeaders($h)->getJson('/api/v1/teacher/payment-receipts')->assertStatus(403);
        $this->withHeaders($h)->postJson("/api/v1/teacher/payment-receipts/{$uuid}/approve")->assertStatus(403);

        $this->assertSame(0, $this->balanceOf($student));
    }

    public function test_index_defaults_to_pending_and_filters_by_status(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $pending = $this->submit($student, 10000);
        $toReject = $this->submit($student, 20000);

        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $h = ['X-Tenant' => 'demo'];

        $this->withHeaders($h)->postJson("/api/v1/teacher/payment-receipts/{$toReject}/reject", ['reason' => 'nope'])->assertOk();

        // Default → pending only.
        $this->withHeaders($h)->getJson('/api/v1/teacher/payment-receipts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $pending);

        // Explicit filter → rejected only.
        $this->withHeaders($h)->getJson('/api/v1/teacher/payment-receipts?status=rejected')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $toReject);
    }

    public function test_cross_tenant_receipt_is_not_found(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $uuid = $this->submit($student, 50000);

        // A teacher in a different tenant cannot see or act on this receipt.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        Sanctum::actingAs($this->member(TenantUserRole::Teacher, [], $other));
        $h = ['X-Tenant' => 'other'];

        $this->withHeaders($h)->getJson("/api/v1/teacher/payment-receipts/{$uuid}")->assertStatus(404);
        $this->withHeaders($h)->postJson("/api/v1/teacher/payment-receipts/{$uuid}/approve")->assertStatus(404);

        $this->assertSame(0, $this->balanceOf($student));
    }
}

<?php

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Models\Invoice;
use App\Modules\Commerce\Models\Payment;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Wallet\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FawryCallback;
use Tests\TestCase;

/**
 * EDU-018: cash at a Fawry outlet. The student gets a reference number and an
 * expiry, Fawry's notification settles it, a replay changes nothing, and a
 * reference nobody paid closes without granting anything.
 */
class FawryGatewayTest extends TestCase
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
        $user = User::factory()->create(['name' => 'أحمد سمير', 'phone' => '01000000123']);
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => TenantUserRole::Student->value,
            'status' => MembershipStatus::Active->value,
            'joined_at' => now(),
        ]);

        $year = $this->year();
        $profile = new StudentProfile(['academic_year_id' => $year->id, 'academic_year' => $year->name, 'study_mode' => 'online']);
        $profile->tenant_id = $this->tenant->id;
        $profile->user_id = $user->id;
        $profile->save();

        return $user;
    }

    private function year(): AcademicYear
    {
        $year = AcademicYear::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->first();

        if ($year === null) {
            $year = new AcademicYear(['name' => 'الثالث الثانوي', 'sort_order' => 0]);
            $year->tenant_id = $this->tenant->id;
            $year->save();
        }

        return $year;
    }

    private function lesson(int $priceMinor = 15000): Lesson
    {
        $lesson = new Lesson([
            'title' => 'الدرس الأول',
            'access_mode' => 'online',
            'price_minor' => $priceMinor,
            'is_purchasable' => true,
            'visibility' => ContentVisibility::Visible->value,
        ]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->academic_year_id = $this->year()->id;
        $lesson->save();

        return $lesson;
    }

    private function fakeCharge(array $overrides = []): void
    {
        Http::fake(['*/payments/charge' => Http::response(array_replace([
            'type' => 'ChargeResponse',
            'referenceNumber' => '9990001',
            'expirationTime' => now()->addHours(72)->getTimestampMs(),
            'statusCode' => 200,
            'statusDescription' => 'Operation done successfully',
        ], $overrides), 200)]);
    }

    /** Places an order and pays with Fawry; returns [order uuid, merchant reference]. */
    private function orderAndCharge(User $student, int $priceMinor = 15000): array
    {
        Sanctum::actingAs($student);

        $orderUuid = $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/checkout/order', [
            'items' => [['type' => 'lesson', 'lesson' => $this->lesson($priceMinor)->id]],
        ])->json('data.uuid');

        $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/checkout/pay', [
            'order' => $orderUuid, 'method' => 'fawry',
        ])->assertOk();

        return [$orderUuid, str_replace('-', '', $orderUuid)];
    }

    public function test_checkout_returns_a_reference_number_and_its_expiry(): void
    {
        $this->fakeCharge();
        $student = $this->student();
        Sanctum::actingAs($student);

        $orderUuid = $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/checkout/order', [
            'items' => [['type' => 'lesson', 'lesson' => $this->lesson()->id]],
        ])->json('data.uuid');

        $response = $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/checkout/pay', [
            'order' => $orderUuid, 'method' => 'fawry',
        ])->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.gateway', 'fawry')
            ->assertJsonPath('data.reference_number', '9990001');

        $this->assertNotNull($response->json('data.expires_at'));
        // Cash at an outlet — there is nothing to redirect to.
        $this->assertNull($response->json('data.redirect_url'));

        $merchantRef = str_replace('-', '', $orderUuid);

        Http::assertSent(function ($request) use ($merchantRef, $student) {
            $body = $request->data();
            $expected = hash('sha256', 'test-merchant'.$merchantRef.$student->id.'PAYATFAWRY'.'150.00'.''.'test-secure-key');

            return $body['merchantCode'] === 'test-merchant'
                && $body['merchantRefNum'] === $merchantRef
                && $body['paymentMethod'] === 'PAYATFAWRY'
                && $body['amount'] === '150.00'
                && $body['chargeItems'][0]['price'] === '150.00'
                && $body['customerMobile'] === '01000000123'
                && $body['signature'] === $expected;
        });

        $this->assertDatabaseHas('payments', [
            'gateway' => 'fawry',
            'status' => Payment::STATUS_PENDING,
            'reference_number' => $merchantRef,
        ]);
        $this->assertNotNull(Payment::withoutGlobalScopes()->where('gateway', 'fawry')->first()->expires_at);
    }

    public function test_a_paid_notification_fulfils_the_order_and_issues_the_invoice(): void
    {
        $this->fakeCharge();
        $student = $this->student();
        [$orderUuid, $merchantRef] = $this->orderAndCharge($student);

        $this->postJson('/api/v1/webhooks/fawry', FawryCallback::notification($merchantRef))
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.order', $orderUuid);

        $this->assertSame(1, Enrollment::withoutGlobalScopes()->where('user_id', $student->id)->count());
        $this->assertDatabaseHas('payments', [
            'gateway' => 'fawry',
            'gateway_txn_id' => '970177',
            'status' => Payment::STATUS_PAID,
        ]);
        $this->assertSame(1, Invoice::withoutGlobalScopes()->count());
        $this->assertSame(
            1,
            LedgerEntry::withoutGlobalScopes()->where('idempotency_key', 'like', '%:teacher_earnings:credit')->count(),
        );
    }

    public function test_a_replayed_notification_changes_nothing(): void
    {
        $this->fakeCharge();
        $student = $this->student();
        [, $merchantRef] = $this->orderAndCharge($student);

        $payload = FawryCallback::notification($merchantRef);

        $this->postJson('/api/v1/webhooks/fawry', $payload)->assertOk()->assertJsonPath('data.status', 'paid');
        $this->postJson('/api/v1/webhooks/fawry', $payload)->assertOk()->assertJsonPath('data.status', 'already_processed');

        $this->assertSame(1, Enrollment::withoutGlobalScopes()->where('user_id', $student->id)->count());
        $this->assertSame(1, Invoice::withoutGlobalScopes()->count());
    }

    public function test_an_expired_reference_closes_and_grants_nothing(): void
    {
        $this->fakeCharge();
        $student = $this->student();
        [, $merchantRef] = $this->orderAndCharge($student);

        $this->postJson('/api/v1/webhooks/fawry', FawryCallback::notification($merchantRef, 'EXPIRED'))
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');

        $this->assertDatabaseHas('payments', ['gateway' => 'fawry', 'status' => Payment::STATUS_EXPIRED]);
        $this->assertSame(0, Enrollment::withoutGlobalScopes()->where('user_id', $student->id)->count());
        $this->assertSame(0, Invoice::withoutGlobalScopes()->count());
    }

    public function test_a_notification_with_a_bad_signature_is_rejected(): void
    {
        $this->fakeCharge();
        $student = $this->student();
        [, $merchantRef] = $this->orderAndCharge($student);

        $payload = FawryCallback::notification($merchantRef);
        $payload['messageSignature'] = 'not-the-signature';

        $this->postJson('/api/v1/webhooks/fawry', $payload)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_signature');

        $this->assertSame(0, Enrollment::withoutGlobalScopes()->where('user_id', $student->id)->count());
    }

    public function test_a_tampered_amount_invalidates_the_signature(): void
    {
        $this->fakeCharge();
        $student = $this->student();
        [, $merchantRef] = $this->orderAndCharge($student);

        $payload = FawryCallback::notification($merchantRef);
        $payload['orderAmount'] = '1.00'; // signed over the original figure

        $this->postJson('/api/v1/webhooks/fawry', $payload)->assertStatus(400);
    }

    public function test_checkout_returns_503_when_fawry_rejects_the_charge(): void
    {
        $this->fakeCharge(['statusCode' => 9946, 'statusDescription' => 'Invalid merchant', 'referenceNumber' => '']);

        $student = $this->student();
        Sanctum::actingAs($student);

        $orderUuid = $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/checkout/order', [
            'items' => [['type' => 'lesson', 'lesson' => $this->lesson()->id]],
        ])->json('data.uuid');

        $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/checkout/pay', [
            'order' => $orderUuid, 'method' => 'fawry',
        ])->assertStatus(503)->assertJsonPath('error.code', 'payment_gateway_unavailable');

        $this->assertDatabaseMissing('payments', ['gateway' => 'fawry']);
    }

    public function test_a_second_attempt_books_its_own_reference(): void
    {
        $this->fakeCharge();
        $student = $this->student();
        [$orderUuid, $merchantRef] = $this->orderAndCharge($student);

        $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/checkout/pay', [
            'order' => $orderUuid, 'method' => 'fawry',
        ])->assertOk();

        Http::assertSent(fn ($request) => ($request->data()['merchantRefNum'] ?? null) === $merchantRef.'-a2');
    }

    public function test_reconciliation_settles_a_payment_whose_notification_never_arrived(): void
    {
        $this->fakeCharge();
        $student = $this->student();
        [, $merchantRef] = $this->orderAndCharge($student);

        // Fawry says the cash was collected; no notification ever reached us.
        Http::fake(['*/payments/status/v2*' => Http::response([
            'statusCode' => 200,
            'orderStatus' => 'PAID',
            'fawryRefNumber' => '970555',
            'orderAmount' => '150.00',
        ], 200)]);

        $this->artisan('fawry:reconcile')->assertSuccessful();

        $this->assertDatabaseHas('payments', [
            'reference_number' => $merchantRef,
            'status' => Payment::STATUS_PAID,
            'gateway_txn_id' => '970555',
        ]);
        $this->assertSame(1, Enrollment::withoutGlobalScopes()->where('user_id', $student->id)->count());
        $this->assertSame(1, Invoice::withoutGlobalScopes()->count());
    }

    public function test_reconciliation_expires_a_lapsed_reference(): void
    {
        $this->fakeCharge();
        $student = $this->student();
        [, $merchantRef] = $this->orderAndCharge($student);

        Http::fake(['*/payments/status/v2*' => Http::response(['statusCode' => 200, 'orderStatus' => 'EXPIRED'], 200)]);

        $this->artisan('fawry:reconcile')->assertSuccessful();

        $this->assertDatabaseHas('payments', ['reference_number' => $merchantRef, 'status' => Payment::STATUS_EXPIRED]);
        $this->assertSame(0, Enrollment::withoutGlobalScopes()->where('user_id', $student->id)->count());
    }

    public function test_reconciliation_closes_a_reference_fawry_has_no_answer_for_once_it_lapses(): void
    {
        $this->fakeCharge();
        $student = $this->student();
        [, $merchantRef] = $this->orderAndCharge($student);

        Payment::withoutGlobalScopes()->where('reference_number', $merchantRef)->update(['expires_at' => now()->subMinute()]);

        Http::fake(['*/payments/status/v2*' => Http::response([], 500)]);

        $this->artisan('fawry:reconcile')->assertSuccessful();

        $this->assertDatabaseHas('payments', ['reference_number' => $merchantRef, 'status' => Payment::STATUS_EXPIRED]);
    }

    public function test_a_still_pending_reference_is_left_alone(): void
    {
        $this->fakeCharge();
        $student = $this->student();
        [, $merchantRef] = $this->orderAndCharge($student);

        Http::fake(['*/payments/status/v2*' => Http::response(['statusCode' => 200, 'orderStatus' => 'UNPAID'], 200)]);

        $this->artisan('fawry:reconcile')->assertSuccessful();

        $this->assertDatabaseHas('payments', ['reference_number' => $merchantRef, 'status' => Payment::STATUS_PENDING]);
    }
}

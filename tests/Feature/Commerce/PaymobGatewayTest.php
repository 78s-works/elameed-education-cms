<?php

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Commerce\Gateways\PaymobGateway;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Models\Payment;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PaymobCallback;
use Tests\TestCase;

/**
 * EDU-002: the live Paymob integration — Intention API on the way out, the
 * signed TRANSACTION callback on the way back. Every HTTP call is faked; the
 * sandbox/live difference is credentials only.
 */
class PaymobGatewayTest extends TestCase
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
        $user = User::factory()->create(['name' => 'سارة محمد علي', 'phone' => '01000000123']);
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

    /** Places an order and returns its uuid. */
    private function order(User $student, Lesson $lesson): string
    {
        Sanctum::actingAs($student);

        return $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/checkout/order', [
            'items' => [['type' => 'lesson', 'lesson' => $lesson->id]],
        ])->json('data.uuid');
    }

    public function test_pay_opens_an_intention_and_redirects_to_the_hosted_checkout(): void
    {
        Http::fake(['*/v1/intention/' => Http::response([
            'id' => 'int_1',
            'client_secret' => 'cs_live_abc',
        ], 201)]);

        $student = $this->student();
        $orderUuid = $this->order($student, $this->lesson());

        $response = $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/checkout/pay', [
            'order' => $orderUuid, 'method' => 'paymob',
        ])->assertOk()->assertJsonPath('data.status', 'pending');

        $redirect = $response->json('data.redirect_url');
        $this->assertStringContainsString('unifiedcheckout', $redirect);
        $this->assertStringContainsString('publicKey=test-public-key', $redirect);
        $this->assertStringContainsString('clientSecret=cs_live_abc', $redirect);

        Http::assertSent(function ($request) use ($orderUuid) {
            $body = $request->data();

            return $request->hasHeader('Authorization', 'Token test-secret-key')
                && $body['amount'] === 15000
                && $body['currency'] === 'EGP'
                && $body['payment_methods'] === [1111]
                && $body['special_reference'] === $orderUuid.'__1'
                && $body['billing_data']['phone_number'] === '01000000123'
                && $body['items'][0]['amount'] === 15000;
        });

        // The order stays pending until the callback lands.
        $this->assertDatabaseHas('payments', [
            'gateway' => 'paymob',
            'status' => Payment::STATUS_PENDING,
            'reference_number' => $orderUuid.'__1',
        ]);
    }

    public function test_pay_returns_503_when_paymob_rejects_the_intention(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['detail' => 'invalid integration'], 400)]);

        $student = $this->student();
        $orderUuid = $this->order($student, $this->lesson());

        $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/checkout/pay', [
            'order' => $orderUuid, 'method' => 'paymob',
        ])->assertStatus(503)->assertJsonPath('error.code', 'payment_gateway_unavailable');

        $this->assertDatabaseMissing('payments', ['gateway' => 'paymob']);
    }

    public function test_a_second_attempt_gets_its_own_special_reference(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_1'], 201)]);

        $student = $this->student();
        $orderUuid = $this->order($student, $this->lesson());

        foreach ([1, 2] as $attempt) {
            $this->withHeaders(['X-Tenant' => 'demo'])->postJson('/api/v1/checkout/pay', [
                'order' => $orderUuid, 'method' => 'paymob',
            ])->assertOk();
        }

        Http::assertSent(fn ($request) => ($request->data()['special_reference'] ?? null) === $orderUuid.'__2');
    }

    public function test_a_signed_callback_fulfils_the_order(): void
    {
        $student = $this->student();
        $lesson = $this->lesson();
        $orderUuid = $this->order($student, $lesson);

        $transaction = PaymobCallback::transaction($orderUuid.'__1');

        $this->postJson(
            '/api/v1/webhooks/paymob?hmac='.PaymobCallback::hmac($transaction),
            PaymobCallback::body($transaction),
        )->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertSame(1, Enrollment::withoutGlobalScopes()->where('user_id', $student->id)->count());
        $this->assertDatabaseHas('payments', [
            'gateway' => 'paymob',
            'gateway_txn_id' => '987654321',
            'status' => Payment::STATUS_PAID,
            'amount_minor' => 15000,
        ]);
    }

    public function test_a_tampered_amount_invalidates_the_signature(): void
    {
        $student = $this->student();
        $orderUuid = $this->order($student, $this->lesson());

        $transaction = PaymobCallback::transaction($orderUuid.'__1');
        $hmac = PaymobCallback::hmac($transaction);
        $transaction['amount_cents'] = 1;

        $this->postJson('/api/v1/webhooks/paymob?hmac='.$hmac, PaymobCallback::body($transaction))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_signature');

        $this->assertSame(0, Enrollment::withoutGlobalScopes()->where('user_id', $student->id)->count());
    }

    public function test_a_callback_signed_with_another_secret_is_rejected(): void
    {
        $student = $this->student();
        $orderUuid = $this->order($student, $this->lesson());

        $transaction = PaymobCallback::transaction($orderUuid.'__1');

        $this->postJson(
            '/api/v1/webhooks/paymob?hmac='.PaymobCallback::hmac($transaction, 'someone-elses-secret'),
            PaymobCallback::body($transaction),
        )->assertStatus(400);
    }

    #[DataProvider('unsuccessfulStates')]
    public function test_an_unsuccessful_transaction_is_recorded_as_failed(array $overrides): void
    {
        $student = $this->student();
        $orderUuid = $this->order($student, $this->lesson());

        $transaction = PaymobCallback::transaction($orderUuid.'__1', 15000, $overrides);

        $this->postJson(
            '/api/v1/webhooks/paymob?hmac='.PaymobCallback::hmac($transaction),
            PaymobCallback::body($transaction),
        )->assertOk()->assertJsonPath('data.status', 'failed');

        $this->assertSame(0, Enrollment::withoutGlobalScopes()->where('user_id', $student->id)->count());
        $this->assertDatabaseHas('payments', ['gateway' => 'paymob', 'status' => Payment::STATUS_FAILED]);
    }

    public static function unsuccessfulStates(): array
    {
        return [
            'declined' => [['success' => false]],
            'still pending' => [['pending' => true]],
            'voided' => [['is_voided' => true]],
            'refunded' => [['is_refunded' => true]],
            'error flagged' => [['error_occured' => true]],
        ];
    }

    public function test_parse_webhook_falls_back_to_the_extras_order_uuid(): void
    {
        $gateway = app(PaymobGateway::class);

        $transaction = PaymobCallback::transaction('');
        unset($transaction['order']['merchant_order_id']);
        $transaction['order']['extras'] = ['creation_extras' => ['order_uuid' => 'order-uuid-from-extras']];

        $parsed = $gateway->parseWebhook(Request::create('/webhooks/paymob', 'POST', PaymobCallback::body($transaction)));

        $this->assertSame('order-uuid-from-extras', $parsed['order_uuid']);
    }

    public function test_a_callback_without_a_transaction_object_never_verifies(): void
    {
        $gateway = app(PaymobGateway::class);

        $request = Request::create('/webhooks/paymob?hmac=anything', 'POST', ['type' => 'TRANSACTION']);

        $this->assertFalse($gateway->verifyWebhook($request));
    }
}

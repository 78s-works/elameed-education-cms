<?php

namespace Tests\Feature\Centers;

use App\Models\User;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Centers\Models\Center;
use App\Modules\Centers\Models\CenterIdCode;
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
 * B20 — Center ID-codes: sequential, grade-encoded (1|2|3), per-center batches.
 * Covers the acceptance triad: uniqueness, grade encoding, per-center scope.
 */
class CenterIdCodeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AcademicYear $year;

    /** Tenant + academic-year headers for the year-scoped id-code routes. */
    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        $this->year = $this->makeYear('Year A');
        $this->h = ['X-Tenant' => 'demo', 'X-Academic-Year' => $this->year->uuid];
    }

    private function makeYear(string $name, int $sort = 0): AcademicYear
    {
        $year = new AcademicYear(['name' => $name, 'sort_order' => $sort]);
        $year->tenant_id = $this->tenant->id;
        $year->save();

        return $year;
    }

    private function member(TenantUserRole $role, array $permissions = []): User
    {
        $u = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value,
            'permissions' => $permissions !== [] ? $permissions : null, 'joined_at' => now(),
        ]);

        return $u;
    }

    private function center(string $name = 'Main'): Center
    {
        $c = new Center(['name' => $name]);
        $c->tenant_id = $this->tenant->id;
        $c->save();

        return $c;
    }

    public function test_batch_is_sequential_grade_encoded_and_shares_a_batch_id(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $center = $this->center();

        $data = $this->withHeaders($this->h)->postJson('/api/v1/teacher/center-id-codes/batch', [
            'center' => $center->uuid, 'grade' => 2, 'count' => 3,
        ])->assertStatus(201)->json('data');

        $this->assertCount(3, $data);
        // Sequential 1..3, grade encoded as leading digit, per-center id embedded.
        $this->assertSame([1, 2, 3], array_column($data, 'sequence'));
        $this->assertSame(2, $data[0]['grade']);
        $this->assertSame(sprintf('2-%d-000001', $center->id), $data[0]['code']);
        $this->assertSame(sprintf('2-%d-000003', $center->id), $data[2]['code']);
        $this->assertSame('active', $data[0]['status']);
        // One generate call = one batch_id across all rows.
        $this->assertSame($data[0]['batch_id'], $data[2]['batch_id']);
    }

    public function test_sequence_is_unique_and_continues_per_center_and_grade(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $center = $this->center();

        // Two grade-1 batches on the same center → sequence keeps counting 1..2, 3..4.
        $this->withHeaders($this->h)->postJson('/api/v1/teacher/center-id-codes/batch', [
            'center' => $center->uuid, 'grade' => 1, 'count' => 2,
        ])->assertStatus(201);
        $second = $this->withHeaders($this->h)->postJson('/api/v1/teacher/center-id-codes/batch', [
            'center' => $center->uuid, 'grade' => 1, 'count' => 2,
        ])->assertStatus(201)->json('data');
        $this->assertSame([3, 4], array_column($second, 'sequence'));

        // A different grade on the same center restarts its own counter at 1.
        $grade3 = $this->withHeaders($this->h)->postJson('/api/v1/teacher/center-id-codes/batch', [
            'center' => $center->uuid, 'grade' => 3, 'count' => 1,
        ])->assertStatus(201)->json('data');
        $this->assertSame(1, $grade3[0]['sequence']);

        // Codes are globally unique within the tenant.
        $all = CenterIdCode::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->pluck('code');
        $this->assertSame($all->count(), $all->unique()->count());
        $this->assertSame(5, $all->count());
    }

    public function test_sequence_scope_is_isolated_per_center(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $a = $this->center('A');
        $b = $this->center('B');

        $this->withHeaders($this->h)->postJson('/api/v1/teacher/center-id-codes/batch', [
            'center' => $a->uuid, 'grade' => 1, 'count' => 3,
        ])->assertStatus(201);
        $bCodes = $this->withHeaders($this->h)->postJson('/api/v1/teacher/center-id-codes/batch', [
            'center' => $b->uuid, 'grade' => 1, 'count' => 2,
        ])->assertStatus(201)->json('data');

        // Center B's counter is independent — starts at 1 despite A already at 3.
        $this->assertSame([1, 2], array_column($bCodes, 'sequence'));
        $this->assertSame(sprintf('1-%d-000001', $b->id), $bCodes[0]['code']);
    }

    public function test_list_filters_used_and_unused(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $center = $this->center();
        $this->withHeaders($this->h)->postJson('/api/v1/teacher/center-id-codes/batch', [
            'center' => $center->uuid, 'grade' => 1, 'count' => 3,
        ])->assertStatus(201);

        // Consume one code (register-time binding is a follow-up; mark it directly).
        CenterIdCode::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('sequence', 1)->update(['status' => 'redeemed']);

        $this->withHeaders($this->h)->getJson('/api/v1/teacher/center-id-codes?filter[status]=unused')
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/center-id-codes?filter[status]=used')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/center-id-codes')
            ->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_index_is_scoped_to_the_active_academic_year(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $center = $this->center();

        // Mint 3 codes while the active year is Year A.
        $this->withHeaders($this->h)->postJson('/api/v1/teacher/center-id-codes/batch', [
            'center' => $center->uuid, 'grade' => 2, 'count' => 3,
        ])->assertStatus(201);

        // Year A sees its 3 codes; a second year sees none of them.
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/center-id-codes')
            ->assertOk()->assertJsonPath('meta.total', 3);

        $yearB = $this->makeYear('Year B', 1);
        $this->withHeaders(['X-Tenant' => 'demo', 'X-Academic-Year' => $yearB->uuid])
            ->getJson('/api/v1/teacher/center-id-codes')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_requests_without_the_year_header_are_rejected(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders(['X-Tenant' => 'demo'])
            ->getJson('/api/v1/teacher/center-id-codes')
            ->assertStatus(422);
    }

    public function test_grade_must_be_one_two_or_three(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $center = $this->center();

        $this->withHeaders($this->h)->postJson('/api/v1/teacher/center-id-codes/batch', [
            'center' => $center->uuid, 'grade' => 4, 'count' => 1,
        ])->assertStatus(422);
    }

    public function test_permission_centers_gates_the_endpoint(): void
    {
        $center = $this->center();

        // Assistant WITHOUT permission:centers → 403.
        Sanctum::actingAs($this->member(TenantUserRole::Assistant, []));
        $this->withHeaders($this->h)->postJson('/api/v1/teacher/center-id-codes/batch', [
            'center' => $center->uuid, 'grade' => 1, 'count' => 1,
        ])->assertStatus(403);

        // Assistant WITH permission:centers → 201.
        Sanctum::actingAs($this->member(TenantUserRole::Assistant, ['centers']));
        $this->withHeaders($this->h)->postJson('/api/v1/teacher/center-id-codes/batch', [
            'center' => $center->uuid, 'grade' => 1, 'count' => 1,
        ])->assertStatus(201);
    }
}

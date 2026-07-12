<?php

namespace Tests\Feature\Centers;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Centers\Models\ActivationCode;
use App\Modules\Centers\Models\Center;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CentersTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        $this->h = ['X-Tenant' => 'demo'];
    }

    private function member(TenantUserRole $role): User
    {
        $u = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        return $u;
    }

    private function course(): Course
    {
        $c = new Course(['title' => 'C', 'visibility' => ContentVisibility::Visible->value]);
        $c->tenant_id = $this->tenant->id;
        $c->slug = 'c-'.uniqid();
        $c->save();

        return $c;
    }

    private function code(array $attrs): ActivationCode
    {
        $c = new ActivationCode(array_merge(['status' => 'active'], $attrs));
        $c->tenant_id = $this->tenant->id;
        $c->save();

        return $c;
    }

    public function test_teacher_creates_center_and_generates_code_batches(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->h)->postJson('/api/v1/teacher/centers', ['name' => 'Main Branch'])
            ->assertStatus(201)->assertJsonPath('data.name', 'Main Branch');

        $wallet = $this->withHeaders($this->h)->postJson('/api/v1/teacher/codes/batch', [
            'type' => 'wallet', 'count' => 3, 'amount_minor' => 5000, 'batch' => 'B1',
        ])->assertStatus(201)->json('data');
        $this->assertCount(3, $wallet);
        $this->assertSame('wallet', $wallet[0]['type']);
        $this->assertSame('active', $wallet[0]['status']);

        $course = $this->course();
        $this->withHeaders($this->h)->postJson('/api/v1/teacher/codes/batch', [
            'type' => 'course', 'count' => 2, 'course_id' => $course->id,
        ])->assertStatus(201);

        $this->withHeaders($this->h)->getJson('/api/v1/teacher/codes')
            ->assertOk()->assertJsonPath('meta.total', 5);
    }

    public function test_course_code_batch_requires_owned_course(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $this->withHeaders($this->h)->postJson('/api/v1/teacher/codes/batch', [
            'type' => 'course', 'count' => 1, 'course_id' => 99999,
        ])->assertStatus(422);
    }

    public function test_student_redeems_wallet_code_and_cannot_reuse_it(): void
    {
        $this->code(['code' => 'WALLET123', 'type' => 'wallet', 'amount_minor' => 5000]);
        $student = $this->member(TenantUserRole::Student);
        Sanctum::actingAs($student);

        $this->withHeaders($this->h)->postJson('/api/v1/codes/redeem', ['code' => 'WALLET123'])
            ->assertOk()->assertJsonPath('data.type', 'wallet')->assertJsonPath('data.amount_minor', 5000);

        $this->withHeaders($this->h)->getJson('/api/v1/wallet')
            ->assertOk()->assertJsonPath('data.balance_minor', 5000);
        $this->assertDatabaseHas('activation_codes', ['code' => 'WALLET123', 'status' => 'redeemed', 'redeemed_by' => $student->id]);

        // Second attempt fails.
        $this->withHeaders($this->h)->postJson('/api/v1/codes/redeem', ['code' => 'WALLET123'])->assertStatus(422);
    }

    public function test_student_redeems_course_code_and_gets_enrolled(): void
    {
        $course = $this->course();
        $this->code(['code' => 'COURSE9', 'type' => 'course', 'course_id' => $course->id]);
        $student = $this->member(TenantUserRole::Student);
        Sanctum::actingAs($student);

        $this->withHeaders($this->h)->postJson('/api/v1/codes/redeem', ['code' => 'COURSE9'])
            ->assertOk()->assertJsonPath('data.type', 'course');

        $this->assertDatabaseHas('enrollments', [
            'tenant_id' => $this->tenant->id, 'user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active',
        ]);
    }

    public function test_teacher_bulk_marks_attendance(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $center = $this->withHeaders($this->h)->postJson('/api/v1/teacher/centers', ['name' => 'Br'])->json('data.uuid');
        $s1 = $this->member(TenantUserRole::Student);
        $s2 = $this->member(TenantUserRole::Student);

        $this->withHeaders($this->h)->postJson("/api/v1/teacher/centers/{$center}/attendance", [
            'students' => [$s1->uuid, $s2->uuid, 'not-a-real-uuid'], 'status' => 'present',
        ])->assertOk()->assertJsonPath('data.marked', 2)->assertJsonCount(1, 'data.skipped');

        $this->withHeaders($this->h)->getJson("/api/v1/teacher/centers/{$center}/attendance")
            ->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_offline_sync_applies_events_idempotently(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        Sanctum::actingAs($teacher);
        $center = new Center(['name' => 'Br']);
        $center->tenant_id = $this->tenant->id;
        $center->save();
        $student = $this->member(TenantUserRole::Student);
        $this->code(['code' => 'WCODE', 'type' => 'wallet', 'amount_minor' => 3000]);

        $events = [
            ['kind' => 'attendance', 'external_ref' => 'a-1', 'center_uuid' => $center->uuid, 'student_uuid' => $student->uuid, 'attended_on' => '2026-07-01'],
            ['kind' => 'redeem', 'external_ref' => 'r-1', 'code' => 'WCODE', 'student_uuid' => $student->uuid],
        ];

        $first = $this->withHeaders($this->h)->postJson('/api/v1/teacher/centers/sync', ['events' => $events])->assertOk()->json('data');
        $this->assertSame('applied', $first[0]['status']);
        $this->assertSame('applied', $first[1]['status']);

        // Re-sending the same batch is idempotent.
        $second = $this->withHeaders($this->h)->postJson('/api/v1/teacher/centers/sync', ['events' => $events])->assertOk()->json('data');
        $this->assertSame('duplicate', $second[0]['status']);
        $this->assertSame('duplicate', $second[1]['status']);

        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('activation_codes', ['code' => 'WCODE', 'status' => 'redeemed']);
    }
}

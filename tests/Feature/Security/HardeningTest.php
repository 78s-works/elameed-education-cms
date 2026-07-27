<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\Question;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Centers\Models\Center;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Services\EnrollmentService;
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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Security & correctness hardening pass (S1). One focused test per flagged item:
 *   1. Membership re-check on student routes (removed / suspended / tenant-scoped)
 *   2. Ledger idempotency (replay posts nothing; balance derivation holds)
 *   3. Exam answer-key non-exposure (list, attempt, and show_answers=off result)
 *   4. IDOR — SubstituteBindings runs AFTER tenant resolution (cross-tenant → 404)
 *   5. Wallet-adjustment audit coverage (every balance mutation is logged)
 */
class HardeningTest extends TestCase
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

    private function member(Tenant $tenant, TenantUserRole $role, MembershipStatus $status = MembershipStatus::Active): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => $status->value, 'joined_at' => now(),
        ]);

        return $user;
    }

    // ── Item 1: membership re-check on student routes ─────────────────────────

    public function test_removed_student_membership_is_denied_immediately(): void
    {
        $student = $this->member($this->tenant, TenantUserRole::Student);
        Sanctum::actingAs($student);

        // Works while an active member…
        $this->withHeaders($this->h)->getJson('/api/v1/me')->assertOk();

        // …remove the membership entirely (not just suspend) → same token now denied.
        TenantUser::where('tenant_id', $this->tenant->id)->where('user_id', $student->id)->delete();

        $this->withHeaders($this->h)->getJson('/api/v1/me')->assertStatus(403);
        $this->withHeaders($this->h)->getJson('/api/v1/wallet')->assertStatus(403);
    }

    public function test_membership_block_is_tenant_scoped(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);

        // Same person: suspended in demo, active in other.
        $user = $this->member($this->tenant, TenantUserRole::Student, MembershipStatus::Suspended);
        TenantUser::create([
            'tenant_id' => $other->id, 'user_id' => $user->id,
            'role' => TenantUserRole::Student->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        Sanctum::actingAs($user);
        $this->withHeaders(['X-Tenant' => 'demo'])->getJson('/api/v1/me')->assertStatus(403);
        $this->withHeaders(['X-Tenant' => 'other'])->getJson('/api/v1/me')->assertOk();
    }

    // ── Item 2: ledger idempotency / balance derivation ───────────────────────

    public function test_replayed_ledger_op_key_posts_exactly_once(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $student = $this->member($this->tenant, TenantUserRole::Student);

        $ledger = app(LedgerService::class);
        $wallet = $ledger->walletFor($this->tenant->id, $student->id);

        $legs = [
            ['account' => LedgerEntry::STUDENT_WALLET, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => 25000, 'wallet_id' => $wallet->id],
            ['account' => LedgerEntry::TEACHER_EARNINGS, 'direction' => LedgerEntry::DEBIT, 'amount_minor' => 25000, 'wallet_id' => null],
        ];

        // Post the SAME operation key three times — must apply exactly once.
        $ledger->post($this->tenant->id, 'topup:op-1', $legs, 'seed', $student->id);
        $ledger->post($this->tenant->id, 'topup:op-1', $legs, 'seed', $student->id);
        $ledger->post($this->tenant->id, 'topup:op-1', $legs, 'seed', $student->id);

        $this->assertSame(25000, $ledger->balance($wallet->fresh()));
        $this->assertSame(2, LedgerEntry::withoutGlobalScopes()->where('wallet_id', $wallet->id)->orWhereNull('wallet_id')->count());

        // A different op key applies on top (balance derived from credits − debits).
        $ledger->post($this->tenant->id, 'topup:op-2', $legs, 'seed', $student->id);
        $this->assertSame(50000, $ledger->balance($wallet->fresh()));
    }

    public function test_unbalanced_ledger_post_is_rejected(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $student = $this->member($this->tenant, TenantUserRole::Student);
        $ledger = app(LedgerService::class);
        $wallet = $ledger->walletFor($this->tenant->id, $student->id);

        $this->expectException(\RuntimeException::class);
        $ledger->post($this->tenant->id, 'bad:op', [
            ['account' => LedgerEntry::STUDENT_WALLET, 'direction' => LedgerEntry::CREDIT, 'amount_minor' => 100, 'wallet_id' => $wallet->id],
            ['account' => LedgerEntry::TEACHER_EARNINGS, 'direction' => LedgerEntry::DEBIT, 'amount_minor' => 90, 'wallet_id' => null],
        ]);
    }

    // ── Item 3: exam answer-key non-exposure ──────────────────────────────────

    public function test_answer_key_is_never_exposed_to_students(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $course = $this->makeCourse();
        // show_answers OFF: even a graded result must not carry the key.
        $exam = $this->makeExam($course, ['show_answers' => false, 'result_visibility' => 'immediate']);
        $q = $this->makeQuestion($exam, ['type' => 'mcq', 'body' => '2+2?', 'options' => ['3', '4', '5'], 'correct' => [1], 'points' => 5]);

        $student = $this->member($this->tenant, TenantUserRole::Student);
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $course, EnrollmentSource::Manual);
        Sanctum::actingAs($student);

        // List: no answer key.
        $list = $this->withHeaders($this->h)->getJson('/api/v1/exams')->assertOk();
        $this->assertStringNotContainsString('"correct"', $list->getContent());

        // Start attempt: questions carry no key.
        $start = $this->withHeaders($this->h)->postJson("/api/v1/exams/{$exam->uuid}/attempts")->assertOk();
        $this->assertArrayNotHasKey('correct', $start->json('data.questions.0'));
        $attemptId = $start->json('data.attempt_id');

        // Submit → graded, but with show_answers off there is no review/key in the result.
        $result = $this->withHeaders($this->h)->postJson("/api/v1/exams/{$exam->uuid}/attempts/{$attemptId}/submit", [
            'answers' => [$q->id => [1]],
        ])->assertOk();
        $this->assertArrayNotHasKey('review', $result->json('data'));
        $this->assertStringNotContainsString('"correct"', $result->getContent());

        // Fetching the stored result again also never leaks it.
        $fetched = $this->withHeaders($this->h)->getJson("/api/v1/exams/{$exam->uuid}/attempts/{$attemptId}")->assertOk();
        $this->assertStringNotContainsString('"correct"', $fetched->getContent());
    }

    // ── Item 4: IDOR — tenant resolved before route-model binding ──────────────

    public function test_cross_tenant_route_model_binding_is_404(): void
    {
        // Tenant B owns the resources; tenant A's teacher tries to reach them by uuid.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        app(TenantContext::class)->setTenant($other);
        $otherCourse = $this->makeCourse('other');
        $otherExam = $this->makeExam($otherCourse);
        $otherCenter = new Center(['name' => 'B Center']);
        $otherCenter->tenant_id = $other->id;
        $otherCenter->save();
        app(TenantContext::class)->forget();

        $teacherA = $this->member($this->tenant, TenantUserRole::Teacher);
        Sanctum::actingAs($teacherA);

        // Valid uuids, but they belong to another tenant → tenant-scoped binding 404s.
        $this->withHeaders($this->h)->getJson("/api/v1/teacher/courses/{$otherCourse->uuid}")->assertStatus(404);
        $this->withHeaders($this->h)->getJson("/api/v1/teacher/exams/{$otherExam->uuid}")->assertStatus(404);
        $this->withHeaders($this->h)->putJson("/api/v1/teacher/centers/{$otherCenter->uuid}", ['name' => 'hijack'])->assertStatus(404);

        // And the resource was not mutated.
        $this->assertDatabaseHas('centers', ['id' => $otherCenter->id, 'name' => 'B Center']);
    }

    // ── Item 5: wallet-adjustment audit coverage ──────────────────────────────

    public function test_wallet_adjust_and_set_are_audit_logged(): void
    {
        $teacher = $this->member($this->tenant, TenantUserRole::Teacher);
        $student = $this->member($this->tenant, TenantUserRole::Student);
        Sanctum::actingAs($teacher);

        $this->withHeaders($this->h)->postJson("/api/v1/teacher/students/{$student->uuid}/wallet/adjust", [
            'amount_minor' => 5000, 'direction' => 'credit', 'reason' => 'scholarship',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'actor_user_id' => $teacher->id,
            'action' => 'wallet.adjust',
            'subject_id' => $student->id,
        ]);

        $this->withHeaders($this->h)->postJson("/api/v1/teacher/students/{$student->uuid}/wallet/set", [
            'balance_minor' => 12000, 'reason' => 'correction',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'actor_user_id' => $teacher->id,
            'action' => 'wallet.set',
            'subject_id' => $student->id,
        ]);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    private function makeCourse(string $slugPrefix = 'demo'): Course
    {
        $c = new Course(['title' => 'Course', 'visibility' => ContentVisibility::Visible->value]);
        $c->tenant_id = app(TenantContext::class)->tenantId();
        $c->slug = $slugPrefix.'-course-'.uniqid();
        $c->save();

        return $c;
    }

    private function makeExam(Course $course, array $attrs = []): Exam
    {
        $exam = new Exam(array_merge(['title' => 'Quiz', 'is_published' => true, 'pass_percent' => 50, 'attempts_allowed' => 0], $attrs));
        $exam->tenant_id = $course->tenant_id;
        $exam->course_id = $course->id;
        $exam->save();

        return $exam;
    }

    private function makeQuestion(Exam $exam, array $attrs): Question
    {
        $q = new Question($attrs);
        $q->tenant_id = $exam->tenant_id;
        $q->exam_id = $exam->id;
        $q->save();

        return $q;
    }
}

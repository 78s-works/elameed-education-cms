<?php

namespace Tests\Feature\Engagement;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Engagement\Models\Review;
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
 * Teacher-panel review CRUD (docs/api/engagement.md): the teacher moderates
 * student reviews and authors curated testimonials in the shared `reviews`
 * table; reviews target a lesson|package (VD §7 — `courses` retired); only
 * visible rows reach the public content page.
 */
class TeacherReviewsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    private function teacher(): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => TenantUserRole::Teacher->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        return $user;
    }

    private function lesson(?Tenant $tenant = null): Lesson
    {
        $tenant ??= $this->tenant;
        $lesson = new Lesson(['title' => 'L', 'visibility' => ContentVisibility::Visible->value]);
        $lesson->tenant_id = $tenant->id;
        $lesson->save(); // academic_year_id auto-filled by Lesson::booted()

        return $lesson;
    }

    /** A student's own review row (created directly — the public flow needs enrollment). */
    private function studentReview(Lesson $lesson, int $rating = 5, bool $visible = true): Review
    {
        $student = User::factory()->create();
        $r = new Review([
            'target_type' => 'lesson', 'target_id' => $lesson->id, 'user_id' => $student->id,
            'rating' => $rating, 'comment' => 'nice', 'is_visible' => $visible,
        ]);
        $r->tenant_id = $this->tenant->id;
        $r->save();

        return $r;
    }

    public function test_teacher_lists_all_reviews_in_the_tenant(): void
    {
        $lesson = $this->lesson();
        $this->studentReview($lesson);
        $this->studentReview($lesson, 4);

        Sanctum::actingAs($this->teacher());
        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/teacher/reviews')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_teacher_creates_a_curated_testimonial(): void
    {
        $lesson = $this->lesson();

        Sanctum::actingAs($this->teacher());
        $this->withHeader('X-Tenant', 'demo')->postJson('/api/v1/teacher/reviews', [
            'target_type' => 'lesson',
            'target_id' => $lesson->id,
            'author_name' => 'Sara M.',
            'rating' => 5,
            'comment' => 'Best lesson ever',
        ])->assertStatus(201)
            ->assertJsonPath('data.student_name', 'Sara M.')
            ->assertJsonPath('data.is_teacher_authored', true)
            ->assertJsonPath('data.rating', 5);

        $this->assertDatabaseHas('reviews', ['target_type' => 'lesson', 'target_id' => $lesson->id, 'user_id' => null, 'author_name' => 'Sara M.']);
    }

    public function test_teacher_can_hide_a_student_review_and_it_leaves_the_public_list(): void
    {
        $lesson = $this->lesson();
        $review = $this->studentReview($lesson);
        $q = "/api/v1/reviews?target_type=lesson&target_id={$lesson->id}";

        // Visible → appears publicly.
        $this->withHeader('X-Tenant', 'demo')->getJson($q)
            ->assertOk()->assertJsonCount(1, 'data');

        // Teacher hides it.
        Sanctum::actingAs($this->teacher());
        $this->withHeader('X-Tenant', 'demo')->putJson("/api/v1/teacher/reviews/{$review->id}", ['is_visible' => false])
            ->assertOk()->assertJsonPath('data.is_visible', false);

        // Gone from the public list; still visible to the teacher.
        $this->withHeader('X-Tenant', 'demo')->getJson($q)
            ->assertOk()->assertJsonCount(0, 'data');
        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/teacher/reviews')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_teacher_deletes_a_review(): void
    {
        $lesson = $this->lesson();
        $review = $this->studentReview($lesson);

        Sanctum::actingAs($this->teacher());
        $this->withHeader('X-Tenant', 'demo')->deleteJson("/api/v1/teacher/reviews/{$review->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }

    public function test_creating_a_testimonial_for_a_foreign_target_is_rejected(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $foreignLesson = $this->lesson($other);

        Sanctum::actingAs($this->teacher());
        // The foreign lesson id fails the tenant-scoped `exists` rule → 422.
        $this->withHeader('X-Tenant', 'demo')->postJson('/api/v1/teacher/reviews', [
            'target_type' => 'lesson',
            'target_id' => $foreignLesson->id,
            'author_name' => 'X',
            'rating' => 5,
        ])->assertStatus(422);
    }

    public function test_cross_tenant_review_update_is_404(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $foreignLesson = $this->lesson($other);
        $foreignReview = new Review(['target_type' => 'lesson', 'target_id' => $foreignLesson->id, 'user_id' => null, 'author_name' => 'Z', 'rating' => 5]);
        $foreignReview->tenant_id = $other->id;
        $foreignReview->save();

        Sanctum::actingAs($this->teacher());
        $this->withHeader('X-Tenant', 'demo')->putJson("/api/v1/teacher/reviews/{$foreignReview->id}", ['rating' => 1])
            ->assertStatus(404);

        $this->assertSame(5, $foreignReview->fresh()->rating);
    }

    public function test_student_cannot_reach_teacher_review_crud(): void
    {
        $student = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $student->id,
            'role' => TenantUserRole::Student->value, 'status' => MembershipStatus::Active->value,
        ]);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/teacher/reviews')->assertStatus(403);
    }
}

<?php

namespace Tests\Feature\Engagement;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Engagement\Models\Comment;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Q&A comments, threaded replies, polymorphic attachments and the teacher forum
 * (M09).
 */
class CommentsAndForumTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Lesson $lesson;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
        [$this->course, $this->lesson] = $this->courseWithLesson();
    }

    private function member(TenantUserRole $role): User
    {
        $user = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        return $user;
    }

    private function courseWithLesson(): array
    {
        $course = new Course(['title' => 'Physics', 'price_minor' => 10000, 'visibility' => ContentVisibility::Visible->value, 'purchase_enabled' => true]);
        $course->tenant_id = $this->tenant->id;
        $course->slug = 'physics-'.uniqid();
        $course->save();

        $unit = new Unit(['course_id' => $course->id, 'title' => 'Unit 1']);
        $unit->tenant_id = $this->tenant->id;
        $unit->save();

        $lesson = new Lesson(['unit_id' => $unit->id, 'course_id' => $course->id, 'title' => 'Lesson 1']);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        return [$course, $lesson];
    }

    private function enroll(User $student): void
    {
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $this->course, EnrollmentSource::Purchase);
    }

    public function test_enrolled_student_asks_and_staff_reply_marks_answered(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $teacher = $this->member(TenantUserRole::Teacher);
        $this->enroll($student);
        $h = ['X-Tenant' => 'demo'];

        Sanctum::actingAs($student);
        $commentUuid = $this->withHeaders($h)->postJson("/api/v1/lessons/{$this->lesson->id}/comments", [
            'body' => 'Why is the sky blue?',
        ])->assertStatus(201)->assertJsonPath('data.status', 'new')->json('data.uuid');

        Sanctum::actingAs($teacher);
        $this->withHeaders($h)->postJson("/api/v1/comments/{$commentUuid}/replies", [
            'body' => 'Rayleigh scattering.',
        ])->assertStatus(201);

        // The question is now answered, with the reply nested.
        Sanctum::actingAs($student);
        $this->withHeaders($h)->getJson("/api/v1/lessons/{$this->lesson->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'answered')
            ->assertJsonPath('data.0.replies.0.body', 'Rayleigh scattering.');
    }

    public function test_unenrolled_student_cannot_view_or_post(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Student)); // not enrolled
        $h = ['X-Tenant' => 'demo'];

        $this->withHeaders($h)->getJson("/api/v1/lessons/{$this->lesson->id}/comments")->assertStatus(403);
        $this->withHeaders($h)->postJson("/api/v1/lessons/{$this->lesson->id}/comments", ['body' => 'hi'])->assertStatus(403);
    }

    public function test_comment_can_carry_an_uploaded_attachment(): void
    {
        Storage::fake('public');
        $student = $this->member(TenantUserRole::Student);
        $this->enroll($student);
        Sanctum::actingAs($student);
        $h = ['X-Tenant' => 'demo'];

        $attachmentUuid = $this->withHeaders($h)->postJson('/api/v1/attachments', [
            'file' => UploadedFile::fake()->image('question.jpg'),
        ])->assertStatus(201)->assertJsonPath('data.kind', 'image')->json('data.uuid');

        $this->withHeaders($h)->postJson("/api/v1/lessons/{$this->lesson->id}/comments", [
            'body' => 'See attached', 'attachment_ids' => [$attachmentUuid],
        ])->assertStatus(201)
            ->assertJsonPath('data.attachments.0.uuid', $attachmentUuid)
            ->assertJsonPath('data.attachments.0.kind', 'image');
    }

    public function test_teacher_forum_aggregates_and_moderates(): void
    {
        $student = $this->member(TenantUserRole::Student);
        $teacher = $this->member(TenantUserRole::Teacher);
        $this->enroll($student);
        $h = ['X-Tenant' => 'demo'];

        Sanctum::actingAs($student);
        $uuid = $this->withHeaders($h)->postJson("/api/v1/lessons/{$this->lesson->id}/comments", ['body' => 'Q1'])
            ->json('data.uuid');

        // Forum lists the question; status filter works.
        Sanctum::actingAs($teacher);
        $this->withHeaders($h)->getJson('/api/v1/teacher/forum?status=new')
            ->assertOk()->assertJsonPath('data.0.uuid', $uuid);

        // Hide it → the student no longer sees it.
        $this->withHeaders($h)->patchJson("/api/v1/teacher/comments/{$uuid}", ['is_hidden' => true])
            ->assertOk()->assertJsonPath('data.is_hidden', true);

        Sanctum::actingAs($student);
        $this->withHeaders($h)->getJson("/api/v1/lessons/{$this->lesson->id}/comments")
            ->assertOk()->assertJsonCount(0, 'data');

        // Delete it.
        Sanctum::actingAs($teacher);
        $this->withHeaders($h)->deleteJson("/api/v1/teacher/comments/{$uuid}")->assertNoContent();
    }

    public function test_cross_tenant_comment_moderation_is_404(): void
    {
        // A comment belonging to another tenant.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $foreignStudent = User::factory()->create();
        $comment = new Comment(['lesson_id' => $this->lesson->id, 'user_id' => $foreignStudent->id, 'body' => 'x']);
        $comment->tenant_id = $other->id;
        $comment->save();

        Sanctum::actingAs($this->member(TenantUserRole::Teacher)); // teacher of demo
        $this->withHeaders(['X-Tenant' => 'demo'])->patchJson("/api/v1/teacher/comments/{$comment->uuid}", ['is_hidden' => true])
            ->assertStatus(404);
    }
}

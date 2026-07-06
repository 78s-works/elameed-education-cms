<?php

namespace Tests\Feature\Engagement;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Engagement\Models\Badge;
use App\Modules\Engagement\Services\PointsService;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GamificationTest extends TestCase
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

    private function student(): User
    {
        $u = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'role' => TenantUserRole::Student->value, 'status' => MembershipStatus::Active->value,
        ]);

        return $u;
    }

    public function test_completing_a_lesson_awards_points_once_and_earns_a_badge(): void
    {
        // A badge that unlocks at 5 points.
        $badge = new Badge(['name' => 'First Step', 'points_threshold' => 5]);
        $badge->tenant_id = $this->tenant->id;
        $badge->save();

        // Course + unit + lesson, student enrolled.
        $course = new Course(['title' => 'C', 'visibility' => ContentVisibility::Visible->value]);
        $course->tenant_id = $this->tenant->id;
        $course->slug = 'c-'.uniqid();
        $course->save();
        $unit = new Unit(['course_id' => $course->id, 'title' => 'U']);
        $unit->tenant_id = $this->tenant->id;
        $unit->save();
        $lesson = new Lesson(['unit_id' => $unit->id, 'course_id' => $course->id, 'title' => 'L']);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        $student = $this->student();
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $course, EnrollmentSource::Manual);
        Sanctum::actingAs($student);

        // Complete the lesson (>=95%).
        $this->withHeaders($this->h)->postJson("/api/v1/lessons/{$lesson->id}/progress", ['watch_percent' => 100])->assertOk();
        // Report again — must NOT double-award (idempotent per lesson).
        $this->withHeaders($this->h)->postJson("/api/v1/lessons/{$lesson->id}/progress", ['watch_percent' => 100])->assertOk();

        $this->withHeaders($this->h)->getJson('/api/v1/me/points')->assertOk()->assertJsonPath('data.total', 5);
        $this->withHeaders($this->h)->getJson('/api/v1/me/badges')->assertOk()->assertJsonPath('data.0.name', 'First Step');
    }

    public function test_leaderboard_orders_by_points_and_respects_hide_ranking(): void
    {
        $svc = app(PointsService::class);
        $top = $this->student();
        $low = $this->student();
        $svc->award($this->tenant->id, $top->id, 50, 'manual');
        $svc->award($this->tenant->id, $low->id, 10, 'manual');

        Sanctum::actingAs($top);
        $this->withHeaders($this->h)->getJson('/api/v1/leaderboard')
            ->assertOk()
            ->assertJsonPath('data.hidden', false)
            ->assertJsonPath('data.entries.0.points', 50)
            ->assertJsonPath('data.entries.1.points', 10);

        // Teacher hides the ranking.
        $p = new TeacherProfile(['hide_ranking' => true]);
        $p->tenant_id = $this->tenant->id;
        $p->save();

        $this->withHeaders($this->h)->getJson('/api/v1/leaderboard')->assertOk()->assertJsonPath('data.hidden', true);
    }
}

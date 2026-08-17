<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Engagement\Models\Review;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Public landing resolution (LANDING_CONTRACT_V2.md) + minimal reviews.
 */
class LandingV2Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
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

    private function publishedCourse(): Course
    {
        $c = new Course(['title' => 'Algebra', 'visibility' => ContentVisibility::Visible->value, 'price_minor' => 10000, 'is_free' => false, 'thumbnail_url' => 'https://cdn.example.com/thumb.jpg']);
        $c->tenant_id = $this->tenant->id;
        $c->slug = 'algebra-'.uniqid();
        $c->save();

        foreach ([600, 900] as $d) { // 25 minutes total
            $l = new Lesson(['course_id' => $c->id, 'title' => 'L', 'duration_sec' => $d]);
            $l->tenant_id = $this->tenant->id;
            $l->save();
        }

        return $c;
    }

    /** A published, individually-purchasable standalone lesson (VD §7 — the
     *  landing "courses" section now lists these instead of courses). */
    private function publishedLesson(int $priceMinor = 5000): Lesson
    {
        $l = new Lesson([
            'title' => 'Standalone lesson',
            'visibility' => ContentVisibility::Visible->value,
            'is_purchasable' => true,
            'price_minor' => $priceMinor,
            'duration_sec' => 600,
            'sort_order' => 1,
        ]);
        $l->tenant_id = $this->tenant->id;
        $l->save();

        return $l;
    }

    private function sectionOfType(array $sections, string $type): ?array
    {
        foreach ($sections as $s) {
            if ($s['type'] === $type) {
                return $s;
            }
        }

        return null;
    }

    public function test_public_landing_resolves_layout_nav_courses_and_reviews(): void
    {
        $course = $this->publishedCourse();
        $lesson = $this->publishedLesson();
        $student = $this->member(TenantUserRole::Student);
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $course, EnrollmentSource::Purchase);

        // A review (seeded directly — the write path is covered separately).
        $r = new Review(['target_type' => 'lesson', 'target_id' => $lesson->id, 'user_id' => $student->id, 'rating' => 5, 'comment' => 'Great course']);
        $r->tenant_id = $this->tenant->id;
        $r->save();

        // Public (unauthenticated) — no default profile → resolver uses defaults().
        $data = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/tenant/landing')
            ->assertOk()
            ->assertJsonPath('data.layout', 'classic')
            ->json('data');

        $this->assertNotEmpty($data['nav']['links']);

        // The "courses" section now lists standalone lessons (VD §7).
        $courses = $this->sectionOfType($data['sections'], 'courses');
        $this->assertNotNull($courses);
        $item = collect($courses['items'])->firstWhere('lesson_id', $lesson->id);
        $this->assertNotNull($item);
        $this->assertSame('lesson', $item['kind']);
        $this->assertSame('online', $item['type']);
        $this->assertSame(5000, $item['price']['amount_minor']);
        $this->assertFalse($item['enrolled']);

        $reviews = $this->sectionOfType($data['sections'], 'testimonials');
        $this->assertNotNull($reviews);
        $this->assertSame('Great course', $reviews['items'][0]['comment']);
        $this->assertSame($lesson->title, $reviews['items'][0]['target_title']);
    }

    public function test_default_landing_carries_a_per_section_variant(): void
    {
        // No profile → resolver uses defaults(); every section must expose a variant.
        $data = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/tenant/landing')->assertOk()->json('data');

        $this->assertSame('split', $this->sectionOfType($data['sections'], 'hero')['variant']);
        $this->assertSame('grid', $this->sectionOfType($data['sections'], 'courses')['variant']);
    }

    public function test_stored_section_without_variant_defaults_to_type_default(): void
    {
        // A legacy section saved before variants existed (no `variant` key).
        $p = new TeacherProfile([
            'locales' => ['ar'],
            'primary_locale' => 'ar',
            'landing_sections' => [
                ['key' => 'courses', 'type' => 'courses', 'visible' => true, 'order' => 1,
                    'content' => ['ar' => ['title' => 'الكورسات']],
                    'config' => ['source' => 'all', 'limit' => 6]],
            ],
        ]);
        $p->tenant_id = $this->tenant->id;
        $p->save();

        $data = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/tenant/landing')->assertOk()->json('data');

        // courses default variant is the first in LandingSchema::VARIANTS['courses'].
        $this->assertSame('grid', $this->sectionOfType($data['sections'], 'courses')['variant']);
    }

    public function test_public_endpoint_echoes_a_stored_non_default_variant(): void
    {
        // The frontend-reported scenario: a teacher picked a NON-default variant
        // (hero → image_bg). The PUBLIC payload must echo the stored value, not
        // fall back to the type default (hero → split). Defaulting is only for
        // legacy rows that never carried a variant.
        $p = new TeacherProfile([
            'locales' => ['ar'],
            'primary_locale' => 'ar',
            'landing_sections' => [
                ['key' => 'hero', 'type' => 'hero', 'variant' => 'image_bg', 'visible' => true, 'order' => 1,
                    'content' => ['ar' => ['title_html' => 'مرحبا']]],
                ['key' => 'courses', 'type' => 'courses', 'variant' => 'carousel', 'visible' => true, 'order' => 2,
                    'content' => ['ar' => ['title' => 'الكورسات']],
                    'config' => ['source' => 'all', 'limit' => 6]],
            ],
        ]);
        $p->tenant_id = $this->tenant->id;
        $p->save();

        $data = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/tenant/landing')->assertOk()->json('data');

        $this->assertSame('image_bg', $this->sectionOfType($data['sections'], 'hero')['variant']);
        $this->assertSame('carousel', $this->sectionOfType($data['sections'], 'courses')['variant']);
    }

    public function test_teacher_can_author_stats_features_and_steps_items(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        // A teacher PUTs real items for the static, item-authored sections.
        $this->withHeader('X-Tenant', 'demo')->putJson('/api/v1/teacher/landing', [
            'locales' => ['ar'],
            'primary_locale' => 'ar',
            'sections' => [
                ['key' => 'stats', 'type' => 'stats', 'visible' => true, 'order' => 1,
                    'content' => ['ar' => ['items' => [
                        ['value' => '+2500', 'label' => 'طالب', 'bogus' => 'dropped'],
                        ['value' => '98%', 'label' => 'نسبة النجاح'],
                    ]]]],
                ['key' => 'features', 'type' => 'features', 'visible' => true, 'order' => 2,
                    'content' => ['ar' => [
                        'title' => 'لماذا أكاديميتنا',
                        'items' => [['icon' => 'fa-video', 'title' => 'شرح فيديو', 'desc' => 'دروس محمية']],
                    ]]],
                ['key' => 'how', 'type' => 'steps', 'visible' => true, 'order' => 3,
                    'content' => ['ar' => ['items' => [['n' => '1', 'title' => 'سجّل', 'desc' => 'أنشئ حسابك']]]]],
            ],
        ])->assertOk()
            ->assertJsonPath('data.sections.0.content.ar.items.0.value', '+2500')
            ->assertJsonPath('data.sections.1.content.ar.items.0.icon', 'fa-video')
            ->assertJsonPath('data.sections.2.content.ar.items.0.title', 'سجّل');

        // Unknown item keys are whitelisted out on save.
        $put = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/teacher/landing')->assertOk()->json('data');
        $this->assertArrayNotHasKey('bogus', $put['sections'][0]['content']['ar']['items'][0]);
        $this->assertSame('نسبة النجاح', $put['sections'][0]['content']['ar']['items'][1]['label']);

        // And the authored items surface on the public resolved endpoint.
        $pub = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/tenant/landing')->assertOk()->json('data');
        $this->assertSame('طالب', $this->sectionOfType($pub['sections'], 'stats')['content']['ar']['items'][0]['label']);
        $this->assertSame('شرح فيديو', $this->sectionOfType($pub['sections'], 'features')['content']['ar']['items'][0]['title']);
    }

    public function test_stats_item_missing_required_field_is_rejected(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        // A stats item without the required `value` is a 422 (not silently dropped).
        $this->withHeader('X-Tenant', 'demo')->putJson('/api/v1/teacher/landing', [
            'locales' => ['ar'], 'primary_locale' => 'ar',
            'sections' => [
                ['key' => 'stats', 'type' => 'stats', 'visible' => true, 'order' => 1,
                    'content' => ['ar' => ['items' => [['label' => 'طالب']]]]],
            ],
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonStructure(['error' => ['details' => ['sections.0.content.ar.items.0.value']]]);
    }

    public function test_landing_courses_section_lists_only_published_purchasable_lessons(): void
    {
        // Published + purchasable → shown.
        $shown = $this->publishedLesson();

        // Not purchasable → hidden.
        $notForSale = new Lesson(['title' => 'Internal', 'visibility' => ContentVisibility::Visible->value, 'is_purchasable' => false, 'price_minor' => 0]);
        $notForSale->tenant_id = $this->tenant->id;
        $notForSale->save();

        // Hidden visibility → hidden even though purchasable.
        $hidden = new Lesson(['title' => 'Hidden', 'visibility' => ContentVisibility::Hidden->value, 'is_purchasable' => true, 'price_minor' => 1000]);
        $hidden->tenant_id = $this->tenant->id;
        $hidden->save();

        $data = $this->withHeader('X-Tenant', 'demo')->getJson('/api/v1/tenant/landing')->assertOk()->json('data');
        $items = collect($this->sectionOfType($data['sections'], 'courses')['items']);

        $this->assertNotNull($items->firstWhere('lesson_id', $shown->id));
        $this->assertNull($items->firstWhere('lesson_id', $notForSale->id));
        $this->assertNull($items->firstWhere('lesson_id', $hidden->id));
    }

    public function test_only_enrolled_student_can_review_and_review_is_upserted(): void
    {
        $lesson = $this->publishedLesson();
        $student = $this->member(TenantUserRole::Student);

        Sanctum::actingAs($student);
        $body = fn (array $extra): array => array_merge(['target_type' => 'lesson', 'target_id' => $lesson->id], $extra);

        // No access → 403.
        $this->withHeader('X-Tenant', 'demo')
            ->postJson('/api/v1/reviews', $body(['rating' => 4]))
            ->assertStatus(403);

        app(EnrollmentService::class)->grantLesson($this->tenant->id, $student->id, $lesson, EnrollmentSource::Purchase);

        $this->withHeader('X-Tenant', 'demo')
            ->postJson('/api/v1/reviews', $body(['rating' => 4, 'comment' => 'good']))
            ->assertStatus(201)->assertJsonPath('data.rating', 4);

        // Second submit updates the same row (one review per student per target).
        $this->withHeader('X-Tenant', 'demo')
            ->postJson('/api/v1/reviews', $body(['rating' => 5, 'comment' => 'even better']))
            ->assertStatus(201)->assertJsonPath('data.rating', 5);

        $this->assertSame(1, Review::withoutGlobalScopes()
            ->where('target_type', 'lesson')->where('target_id', $lesson->id)
            ->where('user_id', $student->id)->count());
    }
}

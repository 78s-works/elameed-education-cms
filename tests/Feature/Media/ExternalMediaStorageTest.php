<?php

namespace Tests\Feature\Media;

use App\Models\User;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Media\Contracts\MediaProvider;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Jobs\RenderRenditionJob;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaRendition;
use App\Modules\Media\Models\PlaybackSession;
use App\Modules\Media\Providers\RemoteMediaProvider;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The external-store video flow: presigned upload straight to the store,
 * presigned segment delivery direct from the store (no app proxying), async
 * transcode, and replace/delete cleanup. `media_local` stands in for the S3
 * store, made presign-capable with the framework's URL-builder hooks so the
 * production behaviour is exercised without a live bucket.
 */
class ExternalMediaStorageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config(['media.provider' => 'remote', 'media.disk' => 'media_local']);
        Storage::fake('media_local');
        Storage::disk('media_local')->buildTemporaryUrlsUsing(
            fn (string $path, $exp, array $opts = []): string => 'https://cdn.example/'.$path.'?sig=abc&exp='.$exp->getTimestamp(),
        );
        Storage::disk('media_local')->buildTemporaryUploadUrlsUsing(
            fn (string $path, $exp, array $opts = []): array => ['url' => 'https://cdn.example/up/'.$path, 'headers' => ['x-amz-acl' => 'private']],
        );

        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    // --- helpers -------------------------------------------------------------

    private function member(TenantUserRole $role): User
    {
        $u = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value,
        ]);

        return $u;
    }

    /** @return array{0: Lesson, 1: MediaAsset} */
    private function lessonWithReadyVideo(): array
    {
        $course = new Course(['title' => 'C', 'visibility' => ContentVisibility::Visible->value, 'price_minor' => 10000]);
        $course->tenant_id = $this->tenant->id;
        $course->slug = 'c-'.uniqid();
        $course->save();
        $unit = new Unit(['course_id' => $course->id, 'title' => 'U']);
        $unit->tenant_id = $this->tenant->id;
        $unit->save();
        $lesson = new Lesson(['unit_id' => $unit->id, 'course_id' => $course->id, 'title' => 'L']);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        $asset = new MediaAsset(['type' => MediaType::HlsVideo->value, 'status' => MediaStatus::Ready->value, 'source_key' => 'media/source/x.mp4']);
        $asset->tenant_id = $this->tenant->id;
        $asset->save();
        Storage::disk('media_local')->put($asset->source_key, 'SOURCE-BYTES');

        $lesson->update(['video_asset_id' => $asset->id]);

        return [$lesson->fresh(), $asset->fresh()];
    }

    private function seedRendition(MediaAsset $asset, int $userId): MediaRendition
    {
        $dir = "media/t{$this->tenant->id}/hls/{$asset->uuid}/{$userId}";
        Storage::disk('media_local')->put("{$dir}/index.m3u8",
            "#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"__KEYURI__\",IV=0x".str_repeat('0', 32)."\n#EXTINF:6.0,\nseg_000.ts\n#EXT-X-ENDLIST\n");
        Storage::disk('media_local')->put("{$dir}/seg_000.ts", 'ENCRYPTED');

        $r = new MediaRendition;
        $r->tenant_id = $this->tenant->id;
        $r->media_asset_id = $asset->id;
        $r->user_id = $userId;
        $r->fill(['status' => 'ready', 'hls_dir' => $dir, 'enc_key' => base64_encode(random_bytes(16)), 'iv' => str_repeat('0', 32), 'segment_count' => 1]);
        $r->save();

        return $r;
    }

    // --- tests ---------------------------------------------------------------

    public function test_remote_provider_is_selected(): void
    {
        $this->assertInstanceOf(RemoteMediaProvider::class, app(MediaProvider::class));
    }

    public function test_async_upload_returns_a_presigned_put_straight_to_the_store(): void
    {
        Sanctum::actingAs($this->member(TenantUserRole::Teacher));

        $res = $this->withHeader('X-Tenant', 'demo')
            ->postJson('/api/v1/teacher/media/uploads', ['filename' => 'lecture.mp4'])
            ->assertStatus(201);

        $upload = $res->json('data.upload');
        $uuid = $res->json('data.media.uuid');

        $this->assertSame('PUT', $upload['method']);
        $this->assertStringStartsWith('https://cdn.example/up/', $upload['upload_url']);

        // The presigned target lands at the asset's tenant-scoped source key, which
        // is persisted so the transcode worker can find it.
        $asset = MediaAsset::withoutGlobalScopes()->where('uuid', $uuid)->first();
        $this->assertSame("media/t{$this->tenant->id}/source/{$uuid}.mp4", $asset->source_key);
        $this->assertStringContainsString($asset->source_key, $upload['upload_url']);
    }

    public function test_playback_delivers_presigned_segment_urls_direct_from_store(): void
    {
        [$lesson, $asset] = $this->lessonWithReadyVideo();
        $student = $this->member(TenantUserRole::Student);
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $lesson->course, EnrollmentSource::Purchase);
        $this->seedRendition($asset, $student->id);

        Sanctum::actingAs($student);
        $data = $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback")->assertOk()->json('data');

        $playlist = $this->get($data['manifest_url'])->assertOk()->getContent();

        // Segments are fetched DIRECTLY from the store via presigned URLs — the app
        // never proxies video bytes.
        $this->assertStringContainsString('https://cdn.example/', $playlist);
        $this->assertStringNotContainsString('/api/v1/media/segment/', $playlist);
        // The tiny key stays behind the token-gated app endpoint.
        $this->assertStringContainsString("/api/v1/media/key/{$data['token']}", $playlist);
    }

    public function test_playback_returns_202_and_queues_transcode_when_rendition_not_ready(): void
    {
        Queue::fake();
        [$lesson, $asset] = $this->lessonWithReadyVideo();
        $student = $this->member(TenantUserRole::Student);
        app(EnrollmentService::class)->grantCourse($this->tenant->id, $student->id, $lesson->course, EnrollmentSource::Purchase);

        Sanctum::actingAs($student);
        $this->withHeader('X-Tenant', 'demo')
            ->postJson("/api/v1/media/lessons/{$lesson->id}/playback")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'processing');

        Queue::assertPushed(RenderRenditionJob::class);
        $this->assertSame('queued', MediaRendition::withoutGlobalScopes()
            ->where('media_asset_id', $asset->id)->where('user_id', $student->id)->value('status'));
    }

    public function test_deleting_a_video_purges_store_objects_rows_and_lesson_link(): void
    {
        [$lesson, $asset] = $this->lessonWithReadyVideo();
        $student = $this->member(TenantUserRole::Student);
        $rendition = $this->seedRendition($asset, $student->id);

        $session = new PlaybackSession([
            'user_id' => $student->id, 'lesson_id' => $lesson->id, 'media_asset_id' => $asset->id,
            'token_hash' => hash('sha256', 'tok'), 'issued_at' => now(), 'expires_at' => now()->addMinutes(5),
        ]);
        $session->tenant_id = $this->tenant->id;
        $session->save();

        Storage::disk('media_local')->assertExists($asset->source_key);
        Storage::disk('media_local')->assertExists("{$rendition->hls_dir}/index.m3u8");

        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $this->withHeader('X-Tenant', 'demo')->deleteJson("/api/v1/teacher/media/{$asset->uuid}")->assertOk();

        $this->assertNull(MediaAsset::withoutGlobalScopes()->find($asset->id));
        $this->assertNull(MediaRendition::withoutGlobalScopes()->find($rendition->id));
        $this->assertSame(0, PlaybackSession::withoutGlobalScopes()->where('media_asset_id', $asset->id)->count());
        $this->assertNull($lesson->fresh()->video_asset_id);
        Storage::disk('media_local')->assertMissing($asset->source_key);
        Storage::disk('media_local')->assertMissing("{$rendition->hls_dir}/index.m3u8");
    }

    public function test_replacing_a_lessons_video_purges_the_previous_asset(): void
    {
        [$lesson, $old] = $this->lessonWithReadyVideo();
        Storage::disk('media_local')->assertExists($old->source_key);

        Sanctum::actingAs($this->member(TenantUserRole::Teacher));
        $file = UploadedFile::fake()->create('new.mp4', 512, 'video/mp4');
        $res = $this->withHeaders(['X-Tenant' => 'demo'])
            ->post('/api/v1/teacher/media/uploads', ['lesson_id' => $lesson->id, 'file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(201);

        $newAsset = MediaAsset::withoutGlobalScopes()->where('uuid', $res->json('data.media.uuid'))->first();
        $this->assertSame($newAsset->id, $lesson->fresh()->video_asset_id);

        // Old asset + its source object are gone.
        $this->assertNull(MediaAsset::withoutGlobalScopes()->find($old->id));
        Storage::disk('media_local')->assertMissing($old->source_key);
    }

    public function test_migration_copies_local_media_to_the_store_idempotently(): void
    {
        Storage::fake('local'); // the "old" app-local disk

        $asset = new MediaAsset(['type' => MediaType::HlsVideo->value, 'status' => MediaStatus::Ready->value, 'source_key' => 'media/source/legacy.mp4']);
        $asset->tenant_id = $this->tenant->id;
        $asset->save();
        Storage::disk('local')->put('media/source/legacy.mp4', 'SRC');

        $user = $this->member(TenantUserRole::Student);
        $r = new MediaRendition;
        $r->tenant_id = $this->tenant->id;
        $r->media_asset_id = $asset->id;
        $r->user_id = $user->id;
        $r->fill(['status' => 'ready', 'hls_dir' => "media/hls/{$asset->uuid}/{$user->id}", 'enc_key' => base64_encode(random_bytes(16)), 'iv' => str_repeat('0', 32), 'segment_count' => 1])->save();
        Storage::disk('local')->put("{$r->hls_dir}/index.m3u8", 'M3U8');
        Storage::disk('local')->put("{$r->hls_dir}/seg_000.ts", 'TS');

        // Dry run writes nothing.
        $this->artisan('media:migrate-to-store --from=local --dry-run')->assertExitCode(0);
        Storage::disk('media_local')->assertMissing('media/source/legacy.mp4');

        // Real run copies source + rendition files, preserving keys.
        $this->artisan('media:migrate-to-store --from=local')->assertExitCode(0);
        Storage::disk('media_local')->assertExists('media/source/legacy.mp4');
        Storage::disk('media_local')->assertExists("{$r->hls_dir}/index.m3u8");
        Storage::disk('media_local')->assertExists("{$r->hls_dir}/seg_000.ts");

        // Idempotent — a second run is a no-op and still succeeds.
        $this->artisan('media:migrate-to-store --from=local')->assertExitCode(0);
        Storage::disk('media_local')->assertExists('media/source/legacy.mp4');
    }
}

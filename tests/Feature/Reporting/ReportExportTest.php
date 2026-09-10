<?php

namespace Tests\Feature\Reporting;

use App\Models\User;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Commerce\Enums\OrderStatus;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Reporting\Enums\ExportStatus;
use App\Modules\Reporting\Jobs\GenerateReportExportJob;
use App\Modules\Reporting\Models\ReportExport;
use App\Modules\Reporting\Services\ReportFileWriter;
use App\Modules\Reporting\Services\ReportRegistry;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Queue\QueueNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Report exports (EDU-021): the request/poll/download cycle, the queued build,
 * tenant isolation, retention, and Arabic in the PDF.
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AcademicYear $year;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'أكاديمية الاختبار', 'status' => TenantStatus::Active]);
        $this->year = $this->makeYear('الثالث الثانوي');
        $this->teacher = $this->member(TenantUserRole::Teacher);
    }

    private function makeYear(string $name): AcademicYear
    {
        $year = new AcademicYear(['name' => $name, 'sort_order' => 0]);
        $year->tenant_id = $this->tenant->id;
        $year->save();

        return $year;
    }

    private function member(TenantUserRole $role, ?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;
        $user = User::factory()->create(['locale' => 'ar']);
        TenantUser::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value,
            'joined_at' => now()->subDays(30),
        ]);

        return $user;
    }

    private function student(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => TenantUserRole::Student->value, 'status' => MembershipStatus::Active->value,
            'joined_at' => now()->subDays(10),
        ]);
        $profile = new StudentProfile(['academic_year_id' => $this->year->id, 'study_mode' => 'online']);
        $profile->tenant_id = $this->tenant->id;
        $profile->user_id = $user->id;
        $profile->save();

        return $user;
    }

    private function paidOrder(User $buyer, int $minor, string $itemTitle): Order
    {
        $order = new Order([
            'user_id' => $buyer->id,
            'status' => OrderStatus::Paid->value,
            'total_minor' => $minor,
            'currency' => 'EGP',
        ]);
        $order->tenant_id = $this->tenant->id;
        $order->save();

        $item = new OrderItem([
            'order_id' => $order->id,
            'item_type' => OrderItem::TYPE_LESSON,
            'title' => $itemTitle,
            'price_minor' => $minor,
        ]);
        $item->tenant_id = $this->tenant->id;
        $item->save();

        return $order;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Tenant' => 'demo'];
    }

    public function test_requesting_an_export_queues_it_on_the_exports_queue(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->teacher);

        $res = $this->withHeaders($this->headers())
            ->postJson('/api/v1/teacher/reports/exports', ['report' => 'sales', 'format' => 'xlsx'])
            ->assertStatus(202);

        $this->assertSame('queued', $res->json('data.status'));
        $this->assertFalse($res->json('data.downloadable'));

        // Not `default`: an export runs for minutes and must not sit in front of
        // a login code (EDU-010).
        Queue::assertPushedOn(QueueNames::Exports, GenerateReportExportJob::class);
    }

    public function test_a_teacher_cannot_request_the_platform_report(): void
    {
        Sanctum::actingAs($this->teacher);

        // Cross-tenant revenue belongs to the admin console; naming it here would
        // have the job build it into a file this teacher can download.
        $this->withHeaders($this->headers())
            ->postJson('/api/v1/teacher/reports/exports', ['report' => 'platform', 'format' => 'xlsx'])
            ->assertStatus(422);
    }

    public function test_the_academic_year_cannot_be_supplied_by_the_client(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/teacher/reports/exports', [
                'report' => 'students',
                'format' => 'xlsx',
                'filters' => ['academic_year_id' => 999],
            ])
            ->assertStatus(422);
    }

    public function test_the_job_builds_an_xlsx_and_marks_the_row_ready(): void
    {
        $student = $this->student('طالب تجريبي');
        $this->paidOrder($student, 15000, 'درس الأحياء');

        Sanctum::actingAs($this->teacher);
        $uuid = $this->withHeaders($this->headers())
            ->postJson('/api/v1/teacher/reports/exports', ['report' => 'sales', 'format' => 'xlsx'])
            ->assertStatus(202)->json('data.uuid');

        $export = ReportExport::query()->where('uuid', $uuid)->firstOrFail();
        (new GenerateReportExportJob((int) $export->id))->handle(
            app(ReportRegistry::class),
            app(ReportFileWriter::class),
            app(TenantContext::class),
        );

        $export->refresh();
        $this->assertSame(ExportStatus::Ready, $export->status);
        $this->assertNotNull($export->file_path);
        $this->assertGreaterThan(0, (int) $export->file_size);
        Storage::disk('local')->assertExists($export->file_path);
        // Retention is set when the file lands, not when it was requested.
        $this->assertNotNull($export->expires_at);
    }

    public function test_a_ready_export_downloads_with_its_own_content_type(): void
    {
        $student = $this->student('طالب');
        $this->paidOrder($student, 5000, 'درس');

        Sanctum::actingAs($this->teacher);
        $uuid = $this->withHeaders($this->headers())
            ->postJson('/api/v1/teacher/reports/exports', ['report' => 'students', 'format' => 'xlsx'])
            ->json('data.uuid');

        $this->runJobFor($uuid);

        $res = $this->withHeaders($this->headers())
            ->get("/api/v1/teacher/reports/exports/{$uuid}/download")
            ->assertOk();

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $res->headers->get('Content-Type'),
        );
        $this->assertStringContainsString('attachment;', (string) $res->headers->get('Content-Disposition'));
        // A report carries names, phone numbers and revenue: never cached.
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function test_a_queued_export_cannot_be_downloaded_yet(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->teacher);

        $uuid = $this->withHeaders($this->headers())
            ->postJson('/api/v1/teacher/reports/exports', ['report' => 'overview', 'format' => 'pdf'])
            ->json('data.uuid');

        $this->withHeaders($this->headers())
            ->getJson("/api/v1/teacher/reports/exports/{$uuid}/download")
            ->assertStatus(404);
    }

    public function test_another_academy_can_neither_see_nor_download_an_export(): void
    {
        Sanctum::actingAs($this->teacher);
        $uuid = $this->withHeaders($this->headers())
            ->postJson('/api/v1/teacher/reports/exports', ['report' => 'students', 'format' => 'csv'])
            ->json('data.uuid');
        $this->runJobFor($uuid);

        $rival = Tenant::create(['slug' => 'rival', 'name' => 'Rival', 'status' => TenantStatus::Active]);
        $outsider = $this->member(TenantUserRole::Teacher, $rival);

        Sanctum::actingAs($outsider);

        // Denied, and it does not matter to the caller which layer said so: the
        // permission gate answers first for a foreign academy (403), and the
        // controller's own tenant check (404 — a foreign export is not
        // acknowledged to exist) stands behind it. Asserting one specific code
        // here would be asserting the order of the defences rather than the
        // isolation itself.
        $this->withHeaders(['X-Tenant' => 'rival'])
            ->getJson("/api/v1/teacher/reports/exports/{$uuid}")
            ->assertForbidden();

        $this->withHeaders(['X-Tenant' => 'rival'])
            ->getJson("/api/v1/teacher/reports/exports/{$uuid}/download")
            ->assertForbidden();

        // The owner still sees their own, so the isolation is a boundary rather
        // than the export having gone missing.
        Sanctum::actingAs($this->teacher);
        $this->withHeaders($this->headers())
            ->getJson('/api/v1/teacher/reports/exports')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_the_pdf_renders_arabic_rather_than_failing_on_it(): void
    {
        $student = $this->student('محمود عبد الرحمن');
        $this->paidOrder($student, 25000, 'الباب الأول — الدعامة والحركة');

        Sanctum::actingAs($this->teacher);
        $uuid = $this->withHeaders($this->headers())
            ->postJson('/api/v1/teacher/reports/exports', [
                'report' => 'sales', 'format' => 'pdf', 'locale' => 'ar',
            ])->json('data.uuid');

        $this->runJobFor($uuid);

        $export = ReportExport::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(ExportStatus::Ready, $export->status);

        $pdf = Storage::disk('local')->get($export->file_path);
        // A PDF at all (dompdf would have produced isolated, reversed glyphs;
        // mPDF shapes and lays out RTL — see PdfRenderer).
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_a_failing_build_records_the_reason_instead_of_hanging_on_processing(): void
    {
        Sanctum::actingAs($this->teacher);
        $uuid = $this->withHeaders($this->headers())
            ->postJson('/api/v1/teacher/reports/exports', ['report' => 'students', 'format' => 'xlsx'])
            ->json('data.uuid');

        $export = ReportExport::query()->where('uuid', $uuid)->firstOrFail();
        // A row whose filters name a year that is not a number: the registry
        // refuses rather than silently exporting everything.
        $export->update(['filters' => ['academic_year_id' => 'not-a-number']]);

        try {
            $this->runJobFor($uuid);
        } catch (\Throwable) {
            // The job rethrows so the queue can retry; the row is what matters.
        }

        $export->refresh();
        $this->assertSame(ExportStatus::Failed, $export->status);
        $this->assertNotNull($export->failure_reason);
        $this->assertNotNull($export->finished_at);
    }

    public function test_purging_removes_the_file_but_keeps_the_row_as_expired(): void
    {
        Sanctum::actingAs($this->teacher);
        $uuid = $this->withHeaders($this->headers())
            ->postJson('/api/v1/teacher/reports/exports', ['report' => 'students', 'format' => 'csv'])
            ->json('data.uuid');
        $this->runJobFor($uuid);

        $export = ReportExport::query()->where('uuid', $uuid)->firstOrFail();
        $path = $export->file_path;
        $export->update(['expires_at' => now()->subDay()]);

        $this->artisan('reports:purge-exports')->assertExitCode(0);

        $export->refresh();
        $this->assertSame(ExportStatus::Expired, $export->status);
        $this->assertNull($export->file_path);
        Storage::disk('local')->assertMissing($path);
        // The row stays, so the teacher is told the file is gone rather than
        // shown a list that pretends the export never happened.
        $this->assertDatabaseHas('report_exports', ['uuid' => $uuid]);
    }

    public function test_a_five_thousand_row_export_completes_in_one_pass(): void
    {
        // The row's own acceptance step 3. Streaming is the point: the writer
        // pulls rows from a Generator, so this costs one row of memory, not 5,000.
        $student = $this->student('طالب الحجم');

        $now = now()->toDateTimeString();
        $orders = [];
        for ($i = 0; $i < 5000; $i++) {
            $orders[] = [
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->id,
                'user_id' => $student->id,
                'status' => OrderStatus::Paid->value,
                'total_minor' => 1000 + $i,
                'currency' => 'EGP',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($orders, 500) as $chunk) {
            DB::table('orders')->insert($chunk);
        }

        // The ledger reports one line per ITEM, so an order with no items is not
        // a sale — the rows have to exist for this to measure anything.
        $orderIds = DB::table('orders')
            ->where('tenant_id', $this->tenant->id)->pluck('id');
        $items = [];
        foreach ($orderIds as $index => $orderId) {
            $items[] = [
                'tenant_id' => $this->tenant->id,
                'order_id' => $orderId,
                'item_type' => OrderItem::TYPE_LESSON,
                'title' => 'درس رقم '.($index + 1),
                'price_minor' => 1000 + $index,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($items, 500) as $chunk) {
            DB::table('order_items')->insert($chunk);
        }

        Sanctum::actingAs($this->teacher);
        $uuid = $this->withHeaders($this->headers())
            ->postJson('/api/v1/teacher/reports/exports', ['report' => 'sales', 'format' => 'csv'])
            ->json('data.uuid');

        $this->runJobFor($uuid);

        $export = ReportExport::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(ExportStatus::Ready, $export->status);
        $this->assertGreaterThanOrEqual(5000, (int) $export->row_count);
    }

    // ── The admin console's platform report (EDU-021) ─────────────────────────

    public function test_the_console_exports_the_platform_report(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        Sanctum::actingAs($admin);

        $uuid = $this->postJson('/api/v1/admin/reports/exports', [
            'format' => 'xlsx',
            'filters' => ['period_days' => 30],
        ])->assertStatus(202)->json('data.uuid');

        $export = ReportExport::query()->where('uuid', $uuid)->firstOrFail();
        // No academy owns it — a platform report spans all of them.
        $this->assertNull($export->tenant_id);

        $this->runJobFor($uuid);
        $export->refresh();

        $this->assertSame(ExportStatus::Ready, $export->status);
        Storage::disk('local')->assertExists($export->file_path);

        $this->get("/api/v1/admin/reports/exports/{$uuid}/download")->assertOk();
    }

    public function test_the_console_will_not_hand_back_an_academys_export(): void
    {
        Sanctum::actingAs($this->teacher);
        $uuid = $this->withHeaders($this->headers())
            ->postJson('/api/v1/teacher/reports/exports', ['report' => 'students', 'format' => 'csv'])
            ->json('data.uuid');
        $this->runJobFor($uuid);

        $admin = User::factory()->create(['is_platform_admin' => true]);
        Sanctum::actingAs($admin);

        // A report FILE was built for whoever asked for it. The console can see
        // any academy's figures through its own screens; handing over one
        // academy's student roster as a file is a different thing.
        $this->getJson("/api/v1/admin/reports/exports/{$uuid}")->assertStatus(404);
        $this->getJson("/api/v1/admin/reports/exports/{$uuid}/download")->assertStatus(404);
    }

    public function test_the_console_route_refuses_an_academy_report(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/reports/exports', [
            'report' => 'sales',
            'format' => 'xlsx',
        ])->assertStatus(422);
    }

    private function runJobFor(string $uuid): void
    {
        $export = ReportExport::query()->where('uuid', $uuid)->firstOrFail();

        (new GenerateReportExportJob((int) $export->id))->handle(
            app(ReportRegistry::class),
            app(ReportFileWriter::class),
            app(TenantContext::class),
        );
    }
}

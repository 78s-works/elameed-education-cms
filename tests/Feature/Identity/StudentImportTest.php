<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Gap 4 — bulk student-history import (.xlsx/.csv). Rows are matched by
 * phone/email to existing students of the tenant and their profile fields are
 * updated in bulk, with a per-row applied|duplicate|failed result.
 */
class StudentImportTest extends TestCase
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

    private function member(TenantUserRole $role, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        TenantUser::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'role' => $role->value, 'status' => MembershipStatus::Active->value, 'joined_at' => now(),
        ]);

        return $user;
    }

    private function xlsxUpload(array $rows): UploadedFile
    {
        $stem = tempnam(sys_get_temp_dir(), 'imp');
        $path = $stem.'.xlsx';
        @unlink($stem);

        $writer = new Writer();
        $writer->openToFile($path);
        foreach ($rows as $r) {
            $writer->addRow(Row::fromValues($r));
        }
        $writer->close();

        return new UploadedFile($path, 'history.xlsx', null, null, true);
    }

    private function csvUpload(array $rows): UploadedFile
    {
        $stem = tempnam(sys_get_temp_dir(), 'imp');
        $path = $stem.'.csv';
        @unlink($stem);

        $lines = array_map(fn ($r) => implode(',', $r), $rows);
        file_put_contents($path, implode("\n", $lines));

        return new UploadedFile($path, 'history.csv', null, null, true);
    }

    // ---- happy path (.xlsx) -----------------------------------------------------

    public function test_teacher_imports_student_history_from_xlsx(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $s1 = $this->member(TenantUserRole::Student, ['phone' => '01000000001']);
        $s2 = $this->member(TenantUserRole::Student, ['phone' => '01000000002']);

        $file = $this->xlsxUpload([
            ['phone', 'gender', 'governorate', 'academic_year'],
            [$s1->phone, 'ذكر', 'Cairo', '3rd Secondary'],
            [$s2->phone, 'أنثى', 'Giza', '2nd Secondary'],
            [$s1->phone, 'ذكر', 'Cairo', '3rd Secondary'],   // duplicate student in the batch
        ]);

        Sanctum::actingAs($teacher);
        $this->withHeaders($this->h)->post('/api/v1/teacher/students/import', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.summary.applied', 2)
            ->assertJsonPath('data.summary.duplicate', 1)
            ->assertJsonPath('data.summary.failed', 0)
            ->assertJsonPath('data.summary.total', 3);

        $this->assertDatabaseHas('student_profiles', [
            'tenant_id' => $this->tenant->id, 'user_id' => $s1->id,
            'gender' => 'ذكر', 'governorate' => 'Cairo', 'academic_year' => '3rd Secondary',
        ]);
        $this->assertDatabaseHas('student_profiles', [
            'tenant_id' => $this->tenant->id, 'user_id' => $s2->id, 'gender' => 'أنثى', 'governorate' => 'Giza',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student.history_imported', 'tenant_id' => $this->tenant->id]);
    }

    // ---- row-level failures (.csv) ---------------------------------------------

    public function test_import_reports_per_row_failures(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        $s1 = $this->member(TenantUserRole::Student, ['phone' => '01000000011']);
        $s2 = $this->member(TenantUserRole::Student, ['phone' => '01000000012']);

        $file = $this->csvUpload([
            ['phone', 'guardian_phone', 'region'],
            [$s1->phone, '01111111111', 'Nasr City'],   // applied
            ['09999999999', '01222222222', 'Nowhere'],  // unknown student → failed
            [$s2->phone, 'not-a-phone', 'Dokki'],        // invalid guardian_phone → failed
        ]);

        Sanctum::actingAs($teacher);
        $response = $this->withHeaders($this->h)->post('/api/v1/teacher/students/import', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.summary.applied', 1)
            ->assertJsonPath('data.summary.failed', 2);

        // Applied row updated s1.
        $this->assertDatabaseHas('student_profiles', [
            'tenant_id' => $this->tenant->id, 'user_id' => $s1->id, 'guardian_phone' => '01111111111', 'region' => 'Nasr City',
        ]);
        // Invalid row did NOT touch s2.
        $this->assertDatabaseMissing('student_profiles', ['user_id' => $s2->id]);

        $results = $response->json('data.results');
        $this->assertSame('failed', collect($results)->firstWhere('row', 3)['status']); // unknown student
        $this->assertSame('failed', collect($results)->firstWhere('row', 4)['status']); // invalid guardian_phone
    }

    // ---- guard ------------------------------------------------------------------

    public function test_import_rejects_non_spreadsheet_file(): void
    {
        $teacher = $this->member(TenantUserRole::Teacher);
        Sanctum::actingAs($teacher);

        $this->withHeaders($this->h)->post('/api/v1/teacher/students/import', [
            'file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ])->assertStatus(422);
    }
}

<?php

namespace Tests\Feature\Documents;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Files\Enums\DocumentPurpose;
use App\Support\Files\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Documents\Concerns\MakesDocuments;
use Tests\TestCase;

/**
 * The two files tabs — the academy's library and the student's own uploads.
 *
 * These are the surfaces the platform never had: nobody could list a teacher's
 * files, measure the storage they were paying for, or find the ones nothing uses
 * any more. Deleting is two steps on purpose, because it cannot be undone.
 */
class DocumentLibraryTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    private function document(DocumentPurpose $purpose, $owner, ?object $attachTo = null): Document
    {
        $factory = Document::factory()->purpose($purpose)->ownedBy($owner)->withBlob();

        if ($attachTo !== null) {
            $factory = $factory->attachedTo($attachTo);
        }

        return $factory->create(['tenant_id' => $this->tenant->id]);
    }

    private function lesson(): Lesson
    {
        $lesson = new Lesson([
            'title' => 'Optics',
            'slug' => 'optics-'.uniqid(),
            'price_minor' => 5000,
            'currency' => 'EGP',
        ]);
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        return $lesson;
    }

    // — the teacher's library —

    public function test_the_library_lists_every_file_in_the_academy(): void
    {
        $teacher = $this->teacher();
        $student = $this->student();

        $this->document(DocumentPurpose::LessonAttachment, $teacher);
        $this->document(DocumentPurpose::PaymentReceipt, $student);

        Sanctum::actingAs($teacher);
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/documents')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_the_library_filters_by_kind_and_purpose(): void
    {
        $teacher = $this->teacher();
        $this->document(DocumentPurpose::LessonAttachment, $teacher);
        $this->document(DocumentPurpose::PaymentReceipt, $this->student());

        Sanctum::actingAs($teacher);
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/documents?purpose=payment_receipt')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.purpose', 'payment_receipt');
    }

    public function test_the_summary_reports_what_the_academy_is_storing(): void
    {
        $teacher = $this->teacher();
        $this->document(DocumentPurpose::LessonAttachment, $teacher);
        $this->document(DocumentPurpose::LessonAttachment, $teacher);

        Sanctum::actingAs($teacher);
        $summary = $this->withHeaders($this->h)->getJson('/api/v1/teacher/documents/summary')
            ->assertOk()->json('data');

        $this->assertSame(2, $summary['count']);
        $this->assertSame(4096, $summary['total_bytes']); // 2 × the factory's 2 KB
        $this->assertSame('lesson_attachment', $summary['by_purpose'][0]['purpose']);
    }

    public function test_an_assistant_needs_the_files_permission(): void
    {
        $this->document(DocumentPurpose::LessonAttachment, $this->teacher());

        Sanctum::actingAs($this->assistant(['students']));
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/documents')->assertStatus(403);

        Sanctum::actingAs($this->assistant(['files']));
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/documents')->assertOk();
    }

    public function test_another_academys_files_are_invisible(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $foreignTeacher = $this->member(TenantUserRole::Teacher, tenant: $other);

        Document::factory()
            ->purpose(DocumentPurpose::LessonAttachment)
            ->ownedBy($foreignTeacher)
            ->create(['tenant_id' => $other->id]);

        Sanctum::actingAs($this->teacher());
        $this->withHeaders($this->h)->getJson('/api/v1/teacher/documents')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // — deleting —

    public function test_a_linked_file_names_its_holder_instead_of_being_deleted(): void
    {
        $teacher = $this->teacher();
        $lesson = $this->lesson();
        $document = $this->document(DocumentPurpose::LessonAttachment, $teacher, $lesson);

        Sanctum::actingAs($teacher);
        $this->withHeaders($this->h)->deleteJson("/api/v1/teacher/documents/{$document->uuid}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'document_still_linked')
            ->assertJsonPath('error.linked_to.type', 'lesson')
            ->assertJsonPath('error.linked_to.label', 'Optics');

        // Nothing was removed on the way to being refused.
        $this->assertDatabaseHas('documents', ['id' => $document->id]);
        Storage::disk($document->disk)->assertExists($document->storage_key);
    }

    public function test_unlinking_then_deleting_removes_the_row_and_the_blob(): void
    {
        $teacher = $this->teacher();
        $document = $this->document(DocumentPurpose::LessonAttachment, $teacher, $this->lesson());

        Sanctum::actingAs($teacher);
        $this->withHeaders($this->h)->deleteJson("/api/v1/teacher/documents/{$document->uuid}/link")
            ->assertOk()
            ->assertJsonPath('data.linked_to', null);

        $this->withHeaders($this->h)->deleteJson("/api/v1/teacher/documents/{$document->uuid}")
            ->assertNoContent();

        // Permanent, by product decision: no soft delete, no orphaned blob.
        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        Storage::disk($document->disk)->assertMissing($document->storage_key);
    }

    // — the student's own files —

    public function test_a_student_sees_only_their_own_uploads(): void
    {
        $student = $this->student();
        $this->document(DocumentPurpose::PaymentReceipt, $student);
        $this->document(DocumentPurpose::PaymentReceipt, $this->student());
        $this->document(DocumentPurpose::LessonAttachment, $this->teacher());

        Sanctum::actingAs($student);
        $this->withHeaders($this->h)->getJson('/api/v1/student/documents')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.purpose', 'payment_receipt');
    }

    public function test_a_corrected_file_is_not_the_students_to_manage(): void
    {
        $student = $this->student();
        $corrected = $this->document(DocumentPurpose::AssignmentCorrected, $this->teacher());

        Sanctum::actingAs($student);

        // Readable through the attempt it belongs to, but never listed here and
        // never deletable — the teacher owns it.
        $this->withHeaders($this->h)->getJson('/api/v1/student/documents')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->withHeaders($this->h)->deleteJson("/api/v1/student/documents/{$corrected->uuid}")
            ->assertStatus(403);
    }

    public function test_a_student_cannot_delete_someone_elses_file(): void
    {
        $document = $this->document(DocumentPurpose::PaymentReceipt, $this->student());

        Sanctum::actingAs($this->student());
        $this->withHeaders($this->h)->deleteJson("/api/v1/student/documents/{$document->uuid}")
            ->assertStatus(403);

        $this->assertDatabaseHas('documents', ['id' => $document->id]);
    }
}

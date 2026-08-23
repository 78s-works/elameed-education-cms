<?php

namespace Tests\Feature\Documents;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Files\Enums\DocumentPurpose;
use App\Support\Files\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Documents\Concerns\MakesDocuments;
use Tests\TestCase;

/**
 * Who may read a stored file.
 *
 * This is the hole the change was built to close: lesson PDFs and comment
 * attachments sat on the public disk at a guessable URL, so paid material needed
 * no credential at all. Access now follows the document's purpose back to the
 * thing that owns it, and a file is never more reachable than that thing.
 */
class DocumentAccessTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    private function lesson(array $attrs = []): Lesson
    {
        $lesson = new Lesson(array_merge([
            'title' => 'Kinematics',
            'slug' => 'kinematics-'.uniqid(),
            'price_minor' => 10000,
            'currency' => 'EGP',
        ], $attrs));
        $lesson->tenant_id = $this->tenant->id;
        $lesson->save();

        return $lesson;
    }

    private function lessonFile(Lesson $lesson, $owner): Document
    {
        return Document::factory()
            ->purpose(DocumentPurpose::LessonAttachment)
            ->ownedBy($owner)
            ->attachedTo($lesson)
            ->withBlob('worksheet')
            ->create(['tenant_id' => $this->tenant->id]);
    }

    private function enroll($student, Lesson $lesson): void
    {
        $enrollment = new Enrollment([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'status' => 'active',
            'source' => 'manual',
        ]);
        $enrollment->tenant_id = $this->tenant->id;
        $enrollment->save();
    }

    public function test_an_enrolled_student_can_download_a_lesson_file(): void
    {
        $teacher = $this->teacher();
        $lesson = $this->lesson();
        $document = $this->lessonFile($lesson, $teacher);

        $student = $this->student();
        $this->enroll($student, $lesson);

        Sanctum::actingAs($student);
        $this->withHeaders($this->h)
            ->get("/api/v1/documents/{$document->uuid}/download")
            ->assertOk();
    }

    public function test_a_student_without_the_lesson_is_refused(): void
    {
        $teacher = $this->teacher();
        $document = $this->lessonFile($this->lesson(), $teacher);

        Sanctum::actingAs($this->student());
        $this->withHeaders($this->h)
            ->get("/api/v1/documents/{$document->uuid}/download")
            ->assertStatus(403);
    }

    public function test_an_anonymous_visitor_is_refused(): void
    {
        $document = $this->lessonFile($this->lesson(), $this->teacher());

        // The old behaviour: a link was the only credential needed.
        $this->withHeaders($this->h)
            ->get("/api/v1/documents/{$document->uuid}/download")
            ->assertStatus(403);
    }

    public function test_a_signed_url_lets_the_browser_fetch_without_a_token(): void
    {
        $teacher = $this->teacher();
        $lesson = $this->lesson();
        $document = $this->lessonFile($lesson, $teacher);

        $student = $this->student();
        $this->enroll($student, $lesson);

        Sanctum::actingAs($student);
        $signed = $this->withHeaders($this->h)
            ->getJson("/api/v1/documents/{$document->uuid}")
            ->assertOk()
            ->json('data.download_url');

        // `<img src>` and `<a download>` cannot send an Authorization header, so
        // the signature carries the authorization instead — but only because the
        // policy already approved this caller when the URL was minted.
        $this->assertStringContainsString('signature=', $signed);
        $this->get($signed)->assertOk();
    }

    public function test_a_tampered_signature_is_refused(): void
    {
        $teacher = $this->teacher();
        $lesson = $this->lesson();
        $document = $this->lessonFile($lesson, $teacher);

        $student = $this->student();
        $this->enroll($student, $lesson);

        Sanctum::actingAs($student);
        $signed = $this->withHeaders($this->h)
            ->getJson("/api/v1/documents/{$document->uuid}")
            ->assertOk()->json('data.download_url');

        // Flipping the signature falls back to the bearer + policy path, so a
        // caller with no claim on the lesson is refused despite holding the URL.
        Sanctum::actingAs($this->student());
        $this->get(preg_replace('/signature=\w+/', 'signature=deadbeef', $signed))
            ->assertStatus(403);
    }

    public function test_the_uploader_can_always_read_their_own_file(): void
    {
        $student = $this->student();

        $document = Document::factory()
            ->purpose(DocumentPurpose::PaymentReceipt)
            ->ownedBy($student)
            ->withBlob()
            ->create(['tenant_id' => $this->tenant->id]);

        Sanctum::actingAs($student);
        $this->withHeaders($this->h)
            ->get("/api/v1/documents/{$document->uuid}/download")
            ->assertOk();
    }

    public function test_another_students_receipt_is_refused(): void
    {
        $document = Document::factory()
            ->purpose(DocumentPurpose::PaymentReceipt)
            ->ownedBy($this->student())
            ->withBlob()
            ->create(['tenant_id' => $this->tenant->id]);

        Sanctum::actingAs($this->student());
        $this->withHeaders($this->h)
            ->get("/api/v1/documents/{$document->uuid}/download")
            ->assertStatus(403);
    }

    public function test_a_finance_assistant_may_read_receipts(): void
    {
        $document = Document::factory()
            ->purpose(DocumentPurpose::PaymentReceipt)
            ->ownedBy($this->student())
            ->withBlob()
            ->create(['tenant_id' => $this->tenant->id]);

        Sanctum::actingAs($this->assistant(['finance']));
        $this->withHeaders($this->h)
            ->get("/api/v1/documents/{$document->uuid}/download")
            ->assertOk();
    }

    public function test_an_assistant_without_finance_may_not(): void
    {
        $document = Document::factory()
            ->purpose(DocumentPurpose::PaymentReceipt)
            ->ownedBy($this->student())
            ->withBlob()
            ->create(['tenant_id' => $this->tenant->id]);

        Sanctum::actingAs($this->assistant(['students']));
        $this->withHeaders($this->h)
            ->get("/api/v1/documents/{$document->uuid}/download")
            ->assertStatus(403);
    }

    public function test_a_public_document_needs_no_credential(): void
    {
        $document = Document::factory()
            ->purpose(DocumentPurpose::BrandingLogo)
            ->ownedBy($this->teacher())
            ->withBlob()
            ->create(['tenant_id' => $this->tenant->id]);

        // A visitor has to see the academy's logo before they can log in.
        $this->withHeaders($this->h)
            ->get("/api/v1/documents/{$document->uuid}/download")
            ->assertOk();
    }

    public function test_a_document_from_another_academy_is_not_found(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => TenantStatus::Active]);
        $foreignTeacher = $this->member(TenantUserRole::Teacher, tenant: $other);

        $document = Document::factory()
            ->purpose(DocumentPurpose::LessonAttachment)
            ->ownedBy($foreignTeacher)
            ->withBlob()
            ->create(['tenant_id' => $other->id]);

        // Even the owner cannot reach it through the wrong host: the tenant scope
        // hides the row entirely, so it is a 404 rather than a 403.
        Sanctum::actingAs($foreignTeacher);
        $this->withHeaders($this->h)
            ->get("/api/v1/documents/{$document->uuid}/download")
            ->assertStatus(404);
    }
}

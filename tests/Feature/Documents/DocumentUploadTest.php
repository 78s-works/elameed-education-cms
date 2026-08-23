<?php

namespace Tests\Feature\Documents;

use App\Support\Files\Enums\DocumentPurpose;
use App\Support\Files\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Documents\Concerns\MakesDocuments;
use Tests\TestCase;

/**
 * POST /documents — the one upload endpoint.
 *
 * What matters here is that the purpose, not the caller, decides where a file
 * lands and what it may be: the eleven old call sites each chose their own disk
 * and their own size limit, and a lesson PDF ended up world-readable on the
 * public disk as a result.
 */
class DocumentUploadTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    public function test_upload_records_the_file_and_returns_it_unattached(): void
    {
        Sanctum::actingAs($student = $this->student());

        $uuid = $this->withHeaders($this->h)->post('/api/v1/documents', [
            'purpose' => 'comment_attachment',
            'file' => UploadedFile::fake()->image('question.jpg'),
        ])->assertStatus(201)
            ->assertJsonPath('data.kind', 'image')
            ->assertJsonPath('data.name', 'question.jpg')
            ->json('data.uuid');

        $document = Document::withoutGlobalScope('tenant')->where('uuid', $uuid)->firstOrFail();

        $this->assertSame($student->id, $document->owner_id);
        $this->assertSame($this->tenant->id, $document->tenant_id);
        // Unattached until the comment that owns it is created (two-phase upload).
        $this->assertFalse($document->isAttached());
        Storage::disk($document->disk)->assertExists($document->storage_key);
    }

    public function test_the_purpose_decides_the_disk_not_the_caller(): void
    {
        Sanctum::actingAs($this->teacher());

        $private = $this->uploadAs('lesson_attachment', UploadedFile::fake()->create('notes.pdf', 8, 'application/pdf'));
        $public = $this->uploadAs('branding_logo', UploadedFile::fake()->image('logo.png'));

        $this->assertSame('local', $private->disk);
        $this->assertFalse($private->isPublic());
        $this->assertNull($private->publicUrl());

        $this->assertSame('public', $public->disk);
        $this->assertTrue($public->isPublic());
        $this->assertNotNull($public->publicUrl());
    }

    public function test_files_are_stored_under_a_tenant_scoped_path(): void
    {
        Sanctum::actingAs($this->teacher());

        $document = $this->uploadAs('lesson_attachment', UploadedFile::fake()->create('notes.pdf', 8, 'application/pdf'));

        // Tenant-first, so a bucket listing never mixes academies and dropping a
        // tenant is a prefix delete.
        $this->assertStringStartsWith("tenants/{$this->tenant->id}/lesson_attachment/", $document->storage_key);
        // The stored name is a ULID, not the uploaded one — an uploaded filename
        // is display text, never a path.
        $this->assertStringNotContainsString('notes', $document->storage_key);
        $this->assertSame('notes.pdf', $document->original_name);
    }

    public function test_size_and_type_limits_come_from_the_purpose(): void
    {
        Sanctum::actingAs($this->teacher());

        // A favicon is capped far below a lesson attachment (512 KB vs 20 MB).
        $this->withHeaders($this->h)->post('/api/v1/documents', [
            'purpose' => 'branding_favicon',
            'file' => UploadedFile::fake()->create('huge.png', 2048, 'image/png'),
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['file']]]);

        // And a receipt only takes an image or a PDF.
        $this->withHeaders($this->h)->post('/api/v1/documents', [
            'purpose' => 'payment_receipt',
            'file' => UploadedFile::fake()->create('macro.xlsx', 4, 'application/vnd.ms-excel'),
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['file']]]);
    }

    public function test_a_student_cannot_upload_a_staff_only_purpose(): void
    {
        Sanctum::actingAs($this->student());

        // Refused server-side, not merely hidden in the UI: a student posting
        // academy branding or lesson material is never legitimate.
        $this->withHeaders($this->h)->post('/api/v1/documents', [
            'purpose' => 'lesson_attachment',
            'file' => UploadedFile::fake()->create('notes.pdf', 8, 'application/pdf'),
        ])->assertStatus(403);

        $this->assertSame(0, Document::withoutGlobalScope('tenant')->count());
    }

    public function test_an_unknown_purpose_is_rejected(): void
    {
        Sanctum::actingAs($this->teacher());

        $this->withHeaders($this->h)->post('/api/v1/documents', [
            'purpose' => 'whatever',
            'file' => UploadedFile::fake()->image('x.png'),
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['purpose']]]);
    }

    public function test_system_generated_purposes_are_not_uploadable(): void
    {
        Sanctum::actingAs($this->teacher());

        // Invoice PDFs are rendered by the app; accepting one over the wire would
        // let a teacher substitute their own.
        $this->withHeaders($this->h)->post('/api/v1/documents', [
            'purpose' => DocumentPurpose::InvoicePdf->value,
            'file' => UploadedFile::fake()->create('fake-invoice.pdf', 8, 'application/pdf'),
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['purpose']]]);
    }
}

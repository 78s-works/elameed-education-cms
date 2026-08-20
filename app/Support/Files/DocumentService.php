<?php

namespace App\Support\Files;

use App\Support\Files\Enums\DocumentKind;
use App\Support\Files\Enums\DocumentPurpose;
use App\Support\Files\Enums\DocumentVisibility;
use App\Support\Files\Exceptions\DocumentException;
use App\Support\Files\Models\Document;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one place in the application that writes a file to disk.
 *
 * Everything a call site used to decide for itself — which disk, what path, what
 * mime and size are allowed, whether the blob gets cleaned up — is decided here,
 * from the purpose. An architecture test enforces that no other class under
 * app/Modules touches Storage, because eleven call sites each inventing their
 * own convention is exactly how this codebase ended up with untracked blobs.
 */
class DocumentService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Store an uploaded file and record it. The document is unattached unless
     * `$options->owner` is given (Pattern B) — Pattern A callers link it by
     * writing the returned id onto their own row.
     */
    public function store(UploadedFile $file, DocumentPurpose $purpose, ?StoreOptions $options = null): Document
    {
        $options ??= new StoreOptions;

        $extension = $this->extensionFor($file);
        $visibility = $options->visibility ?? $purpose->visibility();
        $disk = $visibility->disk();

        $key = $this->pathFor($purpose, $extension);
        $checksum = hash_file('sha256', $file->getRealPath()) ?: null;

        if (Storage::disk($disk)->putFileAs(dirname($key), $file, basename($key)) === false) {
            throw DocumentException::writeFailed($key, $disk);
        }

        return $this->record($file, $purpose, $visibility, $disk, $key, $extension, $checksum, $options);
    }

    /**
     * Store raw bytes the application generated itself — an invoice PDF, a
     * thumbnail extracted from a video. Same rules, no UploadedFile.
     */
    public function storeContents(
        string $bytes,
        string $name,
        DocumentPurpose $purpose,
        ?StoreOptions $options = null,
    ): Document {
        $options ??= new StoreOptions;

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'bin';
        $visibility = $options->visibility ?? $purpose->visibility();
        $disk = $visibility->disk();
        $key = $this->pathFor($purpose, $extension);

        if (Storage::disk($disk)->put($key, $bytes) === false) {
            throw DocumentException::writeFailed($key, $disk);
        }

        $document = new Document([
            'owner_id' => $this->resolveOwnerId($options),
            'purpose' => $purpose,
            'kind' => DocumentKind::fromExtension($extension),
            'visibility' => $visibility,
            'status' => $options->status,
            'disk' => $disk,
            'storage_key' => $key,
            'original_name' => $options->originalName ?? $name,
            'mime' => Storage::disk($disk)->mimeType($key) ?: null,
            'extension' => $extension,
            'size_bytes' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
            'meta' => $options->meta ?: null,
        ]);

        if ($options->owner !== null) {
            $this->setOwnerAttributes($document, $options->owner);
        }

        $document->save();

        return $document;
    }

    /** Link an unattached document to its Pattern B owner. */
    public function attachTo(Document $document, Model $owner): Document
    {
        $this->setOwnerAttributes($document, $owner);
        $document->save();

        return $document;
    }

    /** Clear a Pattern B link, leaving the document in the owner's files tab. */
    public function detach(Document $document): Document
    {
        $document->forceFill([
            'documentable_type' => null,
            'documentable_id' => null,
        ])->save();

        return $document;
    }

    /**
     * Replace a document's bytes in place, keeping its uuid and every reference
     * to it. The old blob is removed only once the new one is safely written.
     */
    public function replace(Document $document, UploadedFile $file): Document
    {
        $extension = $this->extensionFor($file);
        $visibility = $document->visibility;
        $disk = $visibility->disk();
        $key = $this->pathFor($document->purpose, $extension);

        if (Storage::disk($disk)->putFileAs(dirname($key), $file, basename($key)) === false) {
            throw DocumentException::writeFailed($key, $disk);
        }

        $previousDisk = $document->disk;
        $previousKey = $document->storage_key;

        $document->forceFill([
            'disk' => $disk,
            'storage_key' => $key,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'extension' => $extension,
            'size_bytes' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
            'kind' => DocumentKind::fromExtension($extension),
        ])->save();

        Storage::disk($previousDisk)->delete($previousKey);

        return $document;
    }

    /**
     * Delete permanently — row and blob, no soft delete, no recovery. The row is
     * removed inside a transaction that only commits once the blob is gone, so a
     * failed disk delete leaves the document intact rather than dangling.
     */
    public function delete(Document $document): void
    {
        $disk = $document->disk;
        $key = $document->storage_key;

        DB::transaction(function () use ($document, $disk, $key): void {
            $document->delete();

            if (Storage::disk($disk)->exists($key) && ! Storage::disk($disk)->delete($key)) {
                throw DocumentException::deleteFailed($key, $disk);
            }
        });
    }

    /** Public URL for a public document; signed, short-lived URL for a private one. */
    public function url(Document $document): string
    {
        return $document->isPublic()
            ? (string) $document->publicUrl()
            : $this->signedUrl($document);
    }

    /**
     * A signed download URL. Needed because `<img src>`, `<iframe src>` and
     * `<a download>` cannot send an Authorization header — the signature carries
     * the authorization instead, and expires quickly enough to be unshareable.
     */
    public function signedUrl(Document $document, ?int $ttl = null): string
    {
        $ttl ??= (int) config('documents.signed_url_ttl', 300);

        return URL::temporarySignedRoute(
            'documents.download',
            now()->addSeconds($ttl),
            ['document' => $document->uuid],
        );
    }

    /** Raw bytes, for callers that need the contents rather than a response. */
    public function contents(Document $document): string
    {
        $disk = Storage::disk($document->disk);

        if (! $disk->exists($document->storage_key)) {
            throw DocumentException::missingBlob($document->storage_key, $document->disk);
        }

        return (string) $disk->get($document->storage_key);
    }

    public function download(Document $document): StreamedResponse
    {
        $disk = Storage::disk($document->disk);

        if (! $disk->exists($document->storage_key)) {
            throw DocumentException::missingBlob($document->storage_key, $document->disk);
        }

        return $disk->download($document->storage_key, $document->original_name);
    }

    /** Storage totals for a tenant's files tab. */
    public function usageFor(?int $tenantId = null): StorageUsage
    {
        $tenantId ??= $this->context->tenantId();

        return StorageUsage::forTenant($tenantId);
    }

    /**
     * Remove two-phase uploads that were never linked to an owner — a client
     * that uploaded an attachment and then abandoned the comment it belonged to.
     */
    public function pruneUnattached(): int
    {
        $cutoff = now()->subHours((int) config('documents.unattached_ttl_hours', 24));
        $purposes = $this->twoPhasePurposes();
        $pruned = 0;

        Document::query()
            ->withoutGlobalScope('tenant')
            ->unattached()
            ->ofPurpose($purposes)
            ->where('created_at', '<', $cutoff)
            ->chunkById(100, function ($documents) use (&$pruned): void {
                foreach ($documents as $document) {
                    $this->delete($document);
                    $pruned++;
                }
            });

        return $pruned;
    }

    // — internals —

    /**
     * `tenants/{tenant}/{purpose}/{Y}/{m}/{ulid}.{ext}` — tenant-first so a
     * bucket listing never mixes academies and dropping a tenant is a prefix
     * delete; ULID so the name is unguessable and sorts by upload time.
     */
    private function pathFor(DocumentPurpose $purpose, string $extension): string
    {
        $tenantId = $this->context->tenantId() ?? 0;

        return sprintf(
            'tenants/%d/%s/%s/%s.%s',
            $tenantId,
            $purpose->value,
            now()->format('Y/m'),
            (string) Str::ulid(),
            $extension,
        );
    }

    private function record(
        UploadedFile $file,
        DocumentPurpose $purpose,
        DocumentVisibility $visibility,
        string $disk,
        string $key,
        string $extension,
        ?string $checksum,
        StoreOptions $options,
    ): Document {
        $document = new Document([
            'owner_id' => $this->resolveOwnerId($options),
            'purpose' => $purpose,
            'kind' => DocumentKind::fromExtension($extension),
            'visibility' => $visibility,
            'status' => $options->status,
            'disk' => $disk,
            'storage_key' => $key,
            'original_name' => $options->originalName ?? $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'extension' => $extension,
            'size_bytes' => $file->getSize(),
            'checksum' => $checksum,
            'meta' => $options->meta ?: null,
        ]);

        if ($options->owner !== null) {
            $this->setOwnerAttributes($document, $options->owner);
        }

        $document->save();

        return $document;
    }

    private function setOwnerAttributes(Document $document, Model $owner): void
    {
        $document->documentable_type = $owner->getMorphClass();
        $document->documentable_id = $owner->getKey();
    }

    private function resolveOwnerId(StoreOptions $options): int
    {
        $ownerId = $options->ownerId ?? auth()->id();

        if ($ownerId === null) {
            throw DocumentException::noOwner();
        }

        return (int) $ownerId;
    }

    /**
     * The extension is taken from the client name but normalised, and falls back
     * to one guessed from the actual mime — a file named `notes` still lands
     * with a usable extension rather than none.
     */
    private function extensionFor(UploadedFile $file): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension === '') {
            $extension = strtolower((string) $file->guessExtension());
        }

        return $extension !== '' ? $extension : 'bin';
    }

    /** @return array<int, DocumentPurpose> */
    private function twoPhasePurposes(): array
    {
        return [
            DocumentPurpose::CommentAttachment,
            DocumentPurpose::TicketAttachment,
            DocumentPurpose::AssignmentSubmission,
            DocumentPurpose::AssignmentCorrected,
        ];
    }
}

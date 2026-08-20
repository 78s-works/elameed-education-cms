<?php

namespace App\Support\Files\Concerns;

use App\Support\Files\Enums\DocumentPurpose;
use App\Support\Files\Models\Document;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Applied to Pattern B owners — models that hold many files, or whose files are
 * uploaded before the owner exists: Comment, SupportTicket, TicketReply,
 * ExamAttempt, Lesson, Tenant.
 *
 * ExamAttempt carries two different purposes (a student's submission and the
 * teacher's corrected return), which is why `documentsFor()` exists: reading
 * `->documents` unfiltered on that model would mix them.
 */
trait HasDocuments
{
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /** @return Collection<int, Document> */
    public function documentsFor(DocumentPurpose $purpose): Collection
    {
        return $this->documents()->ofPurpose($purpose)->get();
    }

    public function firstDocumentFor(DocumentPurpose $purpose): ?Document
    {
        return $this->documents()->ofPurpose($purpose)->first();
    }
}

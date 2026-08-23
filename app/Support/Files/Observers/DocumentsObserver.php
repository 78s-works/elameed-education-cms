<?php

namespace App\Support\Files\Observers;

use App\Support\Files\Concerns\HasDocuments;
use App\Support\Files\DocumentService;
use Illuminate\Database\Eloquent\Model;

/**
 * Deletes a model's Pattern B documents when the model itself is deleted.
 *
 * This is the fix for the leak the whole change started from: deleting a comment
 * cascaded its attachments row and left the blob on disk forever, with nothing
 * left pointing at it to ever find it again. The database cannot do this for us —
 * a FK cascade would drop the row and still strand the file — so the cleanup runs
 * here, where the blob goes with it.
 *
 * Registered for every model using HasDocuments. Soft-deleting owners are skipped:
 * the row is still recoverable, so its files must be too.
 */
class DocumentsObserver
{
    public function __construct(private readonly DocumentService $documents) {}

    public function deleted(Model $model): void
    {
        if (! in_array(HasDocuments::class, class_uses_recursive($model), true)) {
            return;
        }

        // A soft delete is not a delete: the owner can come back, and coming back
        // to missing attachments would be worse than keeping them.
        if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
            return;
        }

        foreach ($model->documents()->get() as $document) {
            $this->documents->delete($document);
        }
    }
}

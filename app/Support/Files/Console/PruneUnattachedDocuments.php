<?php

namespace App\Support\Files\Console;

use App\Support\Files\DocumentService;
use Illuminate\Console\Command;

/**
 * Collects two-phase uploads that never found an owner.
 *
 * A client uploads an attachment first and links it when it posts the comment or
 * ticket. If it never posts — the tab was closed, the request failed — the row
 * and its blob would sit there forever. Only purposes that require an owner are
 * swept; a branding logo is legitimately unattached until the teacher picks it.
 */
class PruneUnattachedDocuments extends Command
{
    protected $signature = 'documents:prune';

    protected $description = 'Delete uploads that were never linked to an owner';

    public function handle(DocumentService $documents): int
    {
        $pruned = $documents->pruneUnattached();

        $this->info($pruned === 0
            ? 'Nothing to prune.'
            : "Pruned {$pruned} unattached document(s).");

        return self::SUCCESS;
    }
}

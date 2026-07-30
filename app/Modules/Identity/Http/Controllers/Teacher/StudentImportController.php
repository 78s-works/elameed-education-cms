<?php

namespace App\Modules\Identity\Http\Controllers\Teacher;

use App\Modules\Identity\Http\Requests\StudentImportRequest;
use App\Modules\Identity\Services\StudentImportService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;

/**
 * POST /teacher/students/import (M17) — bulk-update matched students' history/
 * profile fields from an `.xlsx`/`.csv` upload. Returns per-row
 * `applied` | `duplicate` | `failed` plus a summary. Gated by `permission:students`.
 */
class StudentImportController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StudentImportService $import,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(StudentImportRequest $request): JsonResponse
    {
        $tenantId = (int) $this->context->tenantOrFail()->getKey();
        $file = $request->file('file');

        $results = $this->import->import(
            $tenantId,
            $file->getPathname(),
            strtolower($file->getClientOriginalExtension()),
        );

        $summary = [
            'total' => count($results),
            'applied' => $this->count($results, 'applied'),
            'duplicate' => $this->count($results, 'duplicate'),
            'failed' => $this->count($results, 'failed'),
        ];

        $this->audit->log('student.history_imported', $summary, $tenantId);

        return response()->json(['data' => ['summary' => $summary, 'results' => $results]]);
    }

    /** @param array<int, array<string, mixed>> $results */
    private function count(array $results, string $status): int
    {
        return count(array_filter($results, fn ($r) => ($r['status'] ?? null) === $status));
    }
}

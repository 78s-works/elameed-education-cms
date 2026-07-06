<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Models\AuditLog;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reads the audit log (M18). Teacher sees their own academy's entries; platform
 * admin sees everything.
 */
class AuditLogController
{
    public function __construct(private readonly TenantContext $context) {}

    public function teacher(Request $request): JsonResponse
    {
        return $this->page(
            AuditLog::query()->where('tenant_id', $this->context->tenantOrFail()->getKey())
        );
    }

    public function admin(Request $request): JsonResponse
    {
        return $this->page(
            AuditLog::query()->when($request->query('tenant'), fn ($q, $t) => $q->where('tenant_id', $t))
        );
    }

    private function page($query): JsonResponse
    {
        $logs = $query->with('actor:id,uuid,name')->latest('id')->paginate(50);

        return response()->json([
            'data' => collect($logs->items())->map(fn (AuditLog $l) => [
                'action' => $l->action,
                'actor' => $l->relationLoaded('actor') ? $l->getRelation('actor')?->name : null,
                'subject_type' => $l->subject_type,
                'subject_id' => $l->subject_id,
                'meta' => $l->meta,
                'ip' => $l->ip,
                'created_at' => $l->created_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()],
        ]);
    }
}

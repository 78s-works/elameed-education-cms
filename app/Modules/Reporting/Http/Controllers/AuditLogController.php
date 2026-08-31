<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Models\AuditLog;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reads the audit log (M18). Teacher sees their own academy's entries; platform
 * admin sees everything.
 *
 * The admin surface is a multi-tenant investigation tool, so it filters: a date
 * range, an event type, an academy, an actor, and free text over actor name and
 * subject id — combinable, and echoed back so the caller can render the active
 * set. The same filters drive the CSV export, so what an admin exports is
 * exactly what they were looking at.
 */
class AuditLogController
{
    /** Rows one CSV export may contain. Beyond this, narrow the filters. */
    private const EXPORT_LIMIT = 5000;

    public function __construct(private readonly TenantContext $context) {}

    public function teacher(Request $request): JsonResponse
    {
        return $this->page(
            AuditLog::query()->where('tenant_id', $this->context->tenantOrFail()->getKey()),
            $request,
        );
    }

    public function admin(Request $request): JsonResponse
    {
        return $this->page($this->filtered(AuditLog::query(), $request), $request);
    }

    /**
     * The event types actually present in the log, for the filter's own select.
     * Listing every action the code *could* write would offer an admin dozens of
     * choices that return nothing.
     */
    public function actions(): JsonResponse
    {
        return response()->json([
            'data' => AuditLog::query()
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action')
                ->values(),
        ]);
    }

    /** The filtered set as CSV — same query as the table, one file. */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered(AuditLog::query(), $request)
            ->with(['actor:id,name', 'tenant:id,name'])
            ->latest('id')
            ->limit(self::EXPORT_LIMIT);

        $filename = 'audit-logs-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            // BOM so Excel opens the Arabic columns as UTF-8 rather than mojibake.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['date', 'action', 'academy', 'actor', 'subject_type', 'subject_id', 'ip']);

            $query->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->created_at?->toIso8601String(),
                        $row->action,
                        $row->tenant?->name,
                        $row->actor?->name,
                        $row->subject_type,
                        $row->subject_id,
                        $row->ip,
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Every admin filter, all optional and all combinable.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    private function filtered(Builder $query, Request $request): Builder
    {
        return $query
            ->when($request->query('from'), fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when($request->query('action'), fn ($q, $action) => $q->where('action', $action))
            // The academy is addressed by uuid in the console's URLs; accept the
            // raw id too so a link built from a row's tenant_id still works.
            ->when($request->query('tenant'), function ($q, $tenant) {
                is_numeric($tenant)
                    ? $q->where('tenant_id', (int) $tenant)
                    : $q->whereHas('tenant', fn ($t) => $t->where('uuid', $tenant));
            })
            ->when($request->query('actor'), function ($q, $actor) {
                is_numeric($actor)
                    ? $q->where('actor_user_id', (int) $actor)
                    : $q->whereHas('actor', fn ($a) => $a->where('uuid', $actor));
            })
            ->when($request->query('q'), function ($q, $term) {
                $like = '%'.$term.'%';
                $q->where(function ($inner) use ($like, $term): void {
                    $inner->whereHas('actor', fn ($a) => $a->where('name', 'like', $like))
                        ->orWhere('subject_id', $term)
                        ->orWhere('action', 'like', $like);
                });
            });
    }

    /**
     * @param  Builder<AuditLog>  $query
     */
    private function page(Builder $query, Request $request): JsonResponse
    {
        $perPage = min(100, max(10, (int) $request->query('per_page', 50)));
        $logs = $query->with(['actor:id,uuid,name', 'tenant:id,uuid,name'])->latest('id')->paginate($perPage);

        return response()->json([
            'data' => collect($logs->items())->map(fn (AuditLog $l) => [
                'action' => $l->action,
                'actor' => $l->relationLoaded('actor') ? $l->getRelation('actor')?->name : null,
                // No actor is two different states — see AuditLogger::log().
                'is_system' => (bool) ($l->meta['system'] ?? false),
                // tenant_id rides along so the console can tell "platform-level
                // action" (no tenant) from "the academy row is gone".
                'tenant_id' => $l->tenant_id,
                'tenant' => $l->relationLoaded('tenant') && $l->getRelation('tenant') !== null ? [
                    'uuid' => $l->getRelation('tenant')->uuid,
                    'name' => $l->getRelation('tenant')->name,
                ] : null,
                'subject_type' => $l->subject_type,
                'subject_id' => $l->subject_id,
                'meta' => $l->meta,
                'ip' => $l->ip,
                'created_at' => $l->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
                'per_page' => $logs->perPage(),
            ],
        ]);
    }
}

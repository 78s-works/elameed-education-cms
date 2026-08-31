<?php

namespace App\Support\Audit;

use App\Modules\Reporting\Models\AuditLog;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\Auth;

/**
 * Records a sensitive write to the audit log. Resolves the actor, tenant, and IP
 * from the current request context unless explicitly provided.
 */
class AuditLogger
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * `$actorId` is for writes that happen away from the authenticated request
     * that ordered them — a queued job, a webhook — where `Auth::id()` is null
     * but the acting user is known to the caller and must still be recorded.
     *
     * A row with no actor at all is marked `system` in its meta when it was
     * written outside an HTTP request (console, scheduler, queue). That keeps
     * "the platform did this" distinct from "nobody recorded who did this" —
     * the console renders the two differently, and on a financial event the
     * difference is the whole point of the log.
     *
     * @param  array<string, mixed>  $meta
     */
    public function log(string $action, array $meta = [], ?int $tenantId = null, ?string $subjectType = null, ?int $subjectId = null, ?int $actorId = null): void
    {
        $actor = $actorId ?? Auth::id();
        $request = app()->runningInConsole() ? null : request();

        if ($actor === null) {
            $meta['system'] = true;
        }

        AuditLog::create([
            'tenant_id' => $tenantId ?? $this->context->tenantId(),
            'actor_user_id' => $actor,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'meta' => $meta,
            'ip' => $request?->ip(),
            'created_at' => now(),
        ]);
    }
}

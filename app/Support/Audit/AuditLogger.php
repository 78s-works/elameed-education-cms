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
     * @param  array<string, mixed>  $meta
     */
    public function log(string $action, array $meta = [], ?int $tenantId = null, ?string $subjectType = null, ?int $subjectId = null): void
    {
        AuditLog::create([
            'tenant_id' => $tenantId ?? $this->context->tenantId(),
            'actor_user_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'meta' => $meta,
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}

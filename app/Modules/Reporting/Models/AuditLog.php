<?php

namespace App\Modules\Reporting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit record. NOT tenant-scoped at the model level (tenant_id is
 * nullable for cross-tenant admin actions); reads filter tenant_id explicitly.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'actor_user_id', 'action', 'subject_type', 'subject_id', 'meta', 'ip',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}

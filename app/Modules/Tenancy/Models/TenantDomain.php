<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Enums\TenantDomainType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Host → tenant mapping row. GLOBAL (read during resolution, before any tenant
 * scope exists), so no BelongsToTenant/RLS. See 03_Data_Model.md §3.
 *
 * @property int $tenant_id
 * @property string $host
 * @property TenantDomainType $type
 * @property bool $is_primary
 */
class TenantDomain extends Model
{
    protected $fillable = [
        'tenant_id',
        'host',
        'type',
        'is_primary',
        'cf_custom_hostname_id',
        'ssl_status',
        'verified_at',
    ];

    protected $casts = [
        'type' => TenantDomainType::class,
        'is_primary' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

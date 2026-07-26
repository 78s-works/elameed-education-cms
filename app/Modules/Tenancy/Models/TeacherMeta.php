<?php

namespace App\Modules\Tenancy\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A single key/value metadata entry the teacher manages from the panel.
 *
 * Tenant-scoped by the BelongsToTenant global scope (auto-fills `tenant_id` on
 * create; every query is constrained to the current tenant). `group` namespaces
 * entries (`seo`, `og`, `general`, …) — unique per (tenant, group, key).
 *
 * @property int $tenant_id
 * @property string $group
 * @property string $key
 * @property string|null $value
 * @property int $sort_order
 */
class TeacherMeta extends Model
{
    use BelongsToTenant;

    // "meta" is a mass noun — pin the table so Eloquent doesn't guess "teacher_metas".
    protected $table = 'teacher_meta';

    protected $fillable = [
        'group',
        'key',
        'value',
        'sort_order',
    ];

    protected $attributes = [
        'group' => 'general',
        'sort_order' => 0,
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];
}

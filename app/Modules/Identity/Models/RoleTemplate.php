<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\RoleTemplateKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Permission;

/**
 * A platform blueprint for a tenant role (M20). Global — see the migration.
 *
 * @property string $key
 * @property bool $is_system
 */
class RoleTemplate extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'is_system',
        'sort_order',
    ];

    protected $casts = [
        'key' => RoleTemplateKey::class,
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_template_permission');
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        return $this->permissions->pluck('name')->values()->all();
    }
}

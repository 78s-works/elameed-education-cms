<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A recorded login attempt (FR-M11-03). Only `created_at` is used (no updates),
 * so timestamps are limited to the created column.
 */
class LoginAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'identifier',
        'ip',
        'user_agent',
        'success',
    ];

    protected $casts = [
        'success' => 'boolean',
    ];
}

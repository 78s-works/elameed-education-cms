<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A permanent, static passwordless login link for a guardian (VD R11).
 * Only the SHA-256 `token_hash` is persisted — the raw token exists solely in the
 * link handed to the parent. Tenant-scoped (BelongsToTenant), so a token minted on
 * one academy host is invisible on another.
 *
 * @property int $parent_user_id
 * @property string $token_hash
 * @property bool $is_active
 */
class ParentMagicLink extends Model
{
    use BelongsToTenant;

    public $timestamps = false; // created_at is DB-defaulted; last_used_at is set explicitly

    protected $fillable = [
        'parent_user_id',
        'token_hash',
        'is_active',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    /** The at-rest form of a raw token. Lookups compare hashes, never plaintext. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}

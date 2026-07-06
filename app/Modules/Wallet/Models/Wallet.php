<?php

namespace App\Modules\Wallet\Models;

use App\Models\User;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Student wallet within one tenant. Balance is DERIVED from ledger_entries
 * (see LedgerService), never stored.
 */
class Wallet extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'currency',
    ];

    protected $attributes = [
        'currency' => 'EGP',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}

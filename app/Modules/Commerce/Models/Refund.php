<?php

namespace App\Modules\Commerce\Models;

use App\Models\User;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A refund posted against a paid order. The money movement itself is in the
 * ledger (`ref_type = 'refund'`); this row is the business record.
 */
class Refund extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const TO_WALLET = 'wallet';

    public const TO_OFFLINE = 'offline';

    protected $fillable = [
        'order_id',
        'amount_minor',
        'currency',
        'destination',
        'reason',
        'revoked_access',
        'refunded_by',
    ];

    protected $attributes = [
        'currency' => 'EGP',
        'destination' => self::TO_WALLET,
        'revoked_access' => false,
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'revoked_access' => 'boolean',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }
}

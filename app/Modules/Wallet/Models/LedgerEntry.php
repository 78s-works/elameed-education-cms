<?php

namespace App\Modules\Wallet\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only double-entry row. Never updated or deleted (03_Data_Model.md §5).
 */
class LedgerEntry extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    // Accounts
    public const STUDENT_WALLET = 'student_wallet';

    public const TEACHER_EARNINGS = 'teacher_earnings';

    public const PLATFORM_COMMISSION = 'platform_commission';

    public const GATEWAY_CLEARING = 'gateway_clearing';

    public const DEBIT = 'debit';

    public const CREDIT = 'credit';

    protected $fillable = [
        'wallet_id',
        'account',
        'direction',
        'amount_minor',
        'ref_type',
        'ref_id',
        'idempotency_key',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
    ];
}

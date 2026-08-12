<?php

namespace App\Modules\Wallet\Models;

use App\Models\User;
use App\Modules\Engagement\Models\Attachment;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual wallet top-up receipt (VD R9/R10). The student uploads a Vodafone Cash /
 * InstaPay receipt image; a teacher or `finance`-assistant approves it — which posts
 * a `student_wallet` credit to the ledger (idempotent on `receipt:{id}`) — or rejects
 * it with a reason. See PaymentReceiptService for the state machine.
 */
class PaymentReceipt extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const METHOD_VODAFONE_CASH = 'vodafone_cash';

    public const METHOD_INSTAPAY = 'instapay';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'method',
        'amount_minor',
        'corrected_amount_minor',
        'currency',
        'attachment_id',
        'status',
        'reviewed_by',
        'reviewed_at',
        'ledger_entry_id',
        'reject_reason',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'corrected_amount_minor' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    /** Sane ceiling for a reviewer-corrected top-up (1,000,000 EGP in piastres). */
    public const MAX_AMOUNT_MINOR = 100_000_000;

    protected $attributes = [
        'currency' => 'EGP',
        'status' => self::STATUS_PENDING,
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** The student who submitted the receipt. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    /** The teacher/assistant who approved or rejected it. */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }
}

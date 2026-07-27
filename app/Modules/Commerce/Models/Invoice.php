<?php

namespace App\Modules\Commerce\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'order_id',
        'number',
        'pdf_url',
        'eta_receipt_uuid',
        'issued_at',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'number' => 'integer',
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

    /** The user who placed the order this invoice bills. */
    public function buyerId(): ?int
    {
        return $this->order?->user_id !== null ? (int) $this->order->user_id : null;
    }

    /** A stored PDF path exists (does not verify the file is on disk). */
    public function hasPdf(): bool
    {
        return is_string($this->pdf_url) && $this->pdf_url !== '';
    }
}

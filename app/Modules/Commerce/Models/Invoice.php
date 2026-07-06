<?php

namespace App\Modules\Commerce\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use BelongsToTenant;

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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

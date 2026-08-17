<?php

namespace App\Modules\Commerce\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use BelongsToTenant;

    /** Recursive content package (B15) — fans out into per-lesson enrollments on buy. */
    public const TYPE_PACKAGE = 'package';

    public const TYPE_LESSON = 'lesson';

    public const TYPE_WALLET_TOPUP = 'wallet_topup';

    public const TYPE_BOOK = 'book';

    protected $fillable = [
        'order_id',
        'item_type',
        'item_id',
        'price_minor',
        'title',
    ];

    protected $casts = [
        'price_minor' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

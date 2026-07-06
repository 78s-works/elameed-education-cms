<?php

namespace App\Modules\Commerce\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use BelongsToTenant;

    public const TYPE_COURSE = 'course';

    public const TYPE_BUNDLE = 'bundle';

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

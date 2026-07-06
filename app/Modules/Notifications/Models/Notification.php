<?php

namespace App\Modules\Notifications\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'channel',
        'type',
        'template_id',
        'payload',
        'status',
        'sent_at',
        'read_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
    ];
}

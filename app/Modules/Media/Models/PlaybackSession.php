<?php

namespace App\Modules\Media\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PlaybackSession extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'lesson_id',
        'media_asset_id',
        'media_version_id',
        'token_hash',
        'device_fingerprint',
        'ip',
        'issued_at',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}

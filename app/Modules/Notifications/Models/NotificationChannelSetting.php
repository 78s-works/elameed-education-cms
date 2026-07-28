<?php

namespace App\Modules\Notifications\Models;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Notification engine — per-tenant channel kill-switch + sender config (doc 10
 * §4, §7). Absence = allowed; `is_active = false` skips the whole channel for
 * the tenant. `config` holds per-tenant sms/mailer sender credentials — a
 * teacher enters his own aggregator data (WE Connekio username/password/
 * account_id/sender). It is cast `encrypted:array` so those secrets are
 * encrypted at rest; the column is empty until a teacher configures a channel.
 *
 * @property bool $is_active
 * @property NotificationChannel $channel
 * @property array<string,mixed>|null $config
 */
class NotificationChannelSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'channel',
        'config',
        'is_active',
    ];

    protected $casts = [
        'channel' => NotificationChannel::class,
        'config' => 'encrypted:array',
        'is_active' => 'boolean',
    ];
}

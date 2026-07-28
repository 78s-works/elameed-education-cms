<?php

namespace App\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notification engine — template copy (doc 10 §3). The actual `title` + `body`
 * for one language of one template. Language fallback (requested → en → first)
 * is applied by the resolver (§6).
 *
 * @property string $language
 * @property string $title
 * @property string $body
 */
class NotificationTemplateTranslation extends Model
{
    protected $fillable = [
        'notification_template_id',
        'language',
        'title',
        'body',
        'created_by',
        'edited_by',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }
}

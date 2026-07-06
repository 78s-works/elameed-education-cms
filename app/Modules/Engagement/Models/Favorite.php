<?php

namespace App\Modules\Engagement\Models;

use App\Modules\Catalog\Models\Course;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Favorite extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'course_id',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}

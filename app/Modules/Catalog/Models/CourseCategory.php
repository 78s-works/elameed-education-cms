<?php

namespace App\Modules\Catalog\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseCategory extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'name',
        'grade',
        'subject',
        'level',
        'section',
        'sort_order',
    ];

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'category_id');
    }
}

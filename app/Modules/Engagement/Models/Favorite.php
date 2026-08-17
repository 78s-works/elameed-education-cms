<?php

namespace App\Modules\Engagement\Models;

use App\Support\Traits\BelongsToAcademicYear;
use App\Support\Traits\BelongsToTenant;
use App\Support\Traits\HasContentTarget;
use Illuminate\Database\Eloquent\Model;

class Favorite extends Model
{
    use BelongsToAcademicYear;
    use BelongsToTenant;
    use HasContentTarget;

    protected $fillable = [
        'academic_year_id',
        'user_id',
        'target_type',
        'target_id',
    ];

    protected $casts = [
        'target_id' => 'integer',
    ];
}

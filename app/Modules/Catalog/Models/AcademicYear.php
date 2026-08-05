<?php

namespace App\Modules\Catalog\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AcademicYear extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'name',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /** Only `uuid` is a generated UUID column; the PK stays an auto-increment bigint. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }
}

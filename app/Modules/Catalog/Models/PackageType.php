<?php

namespace App\Modules\Catalog\Models;

use App\Support\Traits\BelongsToAcademicYear;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A teacher-defined type/category for content {@see Package}s (B27). Scoped to
 * one tenant and one academic year (the year is the ceiling — a package may only
 * carry a type from its own year). Deleting a type nulls its packages' foreign
 * key (nullOnDelete) rather than deleting them, so it is a soft label.
 *
 * Bound by uuid under the tenant + academic-year scope, mirroring AcademicYear.
 */
class PackageType extends Model
{
    use BelongsToAcademicYear;
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'name',
        'sort_order',
        'description',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /** Only `uuid` is a generated UUID column; the PK stays an auto-increment bigint. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** Packages tagged with this type (the FK nulls if the type is deleted). */
    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }
}

<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-academy registration details for a student (the sign-up form fields).
 * Tenant-scoped; one row per (tenant, student).
 */
class StudentProfile extends Model
{
    use BelongsToTenant;

    /** The editable registration fields (free-text; frontend owns dropdown options). */
    public const FIELDS = ['gender', 'governorate', 'region', 'academic_year', 'education_type', 'guardian_phone'];

    protected $fillable = [
        'user_id',
        'gender',
        'governorate',
        'region',
        'academic_year',
        'education_type',
        'guardian_phone',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Validation rules for the profile fields. Pass a prefix for nested payloads
     * (e.g. 'profile' → 'profile.gender'); default is flat/top-level.
     *
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = ''): array
    {
        $p = $prefix === '' ? '' : $prefix.'.';

        return [
            $p.'gender' => ['nullable', 'string', 'max:20'],
            $p.'governorate' => ['nullable', 'string', 'max:100'],
            $p.'region' => ['nullable', 'string', 'max:100'],
            $p.'academic_year' => ['nullable', 'string', 'max:100'],
            $p.'education_type' => ['nullable', 'string', 'max:100'],
            $p.'guardian_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+]{6,30}$/'],
        ];
    }

    /** Pluck only the profile fields present in the given data. */
    public static function fields(array $data): array
    {
        return array_intersect_key($data, array_flip(self::FIELDS));
    }
}

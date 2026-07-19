<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Support\LandingSchema;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant branding + landing configuration (one row per tenant).
 *
 * FIRST tenant-scoped model: the BelongsToTenant scope filters every query to
 * the current tenant and auto-fills `tenant_id` on create. On MySQL this app
 * scope is the ONLY isolation guard (no RLS) — covered by the cross-tenant
 * isolation test.
 *
 * @property int $tenant_id
 * @property array<string, mixed>|null $contact
 * @property array<string, mixed>|null $socials
 * @property array<int, array{key: string, visible: bool}>|null $landing_sections
 * @property list<string>|null $locales
 * @property string $primary_locale
 */
class TeacherProfile extends Model
{
    use BelongsToTenant;

    /** Landing sections a teacher can show/hide/reorder + author (FR-M02-04). */
    public const LANDING_SECTION_KEYS = LandingSchema::TYPES;

    protected $fillable = [
        'logo_url',
        'cover_url',
        'primary_color',
        'secondary_color',
        'bio',
        'contact',
        'socials',
        'landing_sections',
        'locales',
        'primary_locale',
        'layout',
        'hide_ranking',
    ];

    protected $attributes = [
        'layout' => 'classic',
    ];

    protected $casts = [
        'contact' => 'array',
        'socials' => 'array',
        'landing_sections' => 'array',
        'locales' => 'array',
        'hide_ranking' => 'boolean',
    ];
}

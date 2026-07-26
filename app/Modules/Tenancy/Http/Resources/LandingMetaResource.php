<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public "render the landing" bundle (GET /tenant/landing/meta): the tenant's
 * identity + branding/theme + its teacher-managed key/value metadata (SEO/OG
 * tags, custom head data). Everything the SPA needs to paint the landing's
 * `<head>` and branding shell in one call.
 *
 * `meta` is grouped by the entry's `group` (`seo`, `og`, `general`, …); the
 * controller eager-loads `metaEntries` already ordered (group → sort_order →
 * key), so each group's array preserves that order. Contact details, auth
 * switches, and feature flags are intentionally NOT here — those live in
 * GET /teacher/profile (auth) and GET /tenant/context.
 *
 * @mixin Tenant
 */
class LandingMetaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->teacherProfile;

        return [
            'site' => [
                'slug' => $this->slug,
                'name' => $this->name,
            ],
            'branding' => [
                'logo_url' => $profile?->logo_url,
                'favicon_url' => $profile?->favicon_url,
                'cover_url' => $profile?->cover_url,
                'primary_color' => $profile?->primary_color,
                'secondary_color' => $profile?->secondary_color,
                'bio' => $profile?->bio,
                'socials' => $profile?->socials ?? (object) [],
            ],
            'meta' => $this->groupedMeta(),
        ];
    }

    /**
     * Group the loaded metadata entries by `group` into
     * `{ "<group>": [ { "key": …, "value": … }, … ] }`. Empty object when none.
     *
     * @return array<string, list<array{key: string, value: string|null}>>|object
     */
    private function groupedMeta(): array|object
    {
        $grouped = $this->metaEntries
            ->groupBy('group')
            ->map(fn ($entries) => $entries
                ->map(fn ($m) => ['key' => $m->key, 'value' => $m->value])
                ->values()
                ->all())
            ->all();

        // Keep the JSON `{}` (not `[]`) when there are no groups.
        return $grouped === [] ? (object) [] : $grouped;
    }
}

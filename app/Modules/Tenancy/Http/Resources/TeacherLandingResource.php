<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Services\LandingResolver;
use App\Modules\Tenancy\Support\LandingSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Teacher's editable landing state (LANDING_CONTRACT_V2, multi-language authoring
 * shape): the enabled `locales` + `primary_locale`, `layout`, derived `nav`, and
 * ALL sections (incl. hidden) with PER-LOCALE content, and `config` (not resolved
 * `items`) for dynamic sections, so the editor renders controls not preview data.
 *
 * @mixin TeacherProfile
 */
class TeacherLandingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $resolver = app(LandingResolver::class);
        $meta = LandingSchema::normalizeLocales($this->locales, $this->primary_locale);
        $sections = $this->landing_sections ?: LandingSchema::defaults($meta['primary']);

        return [
            'layout' => $resolver->normalizeLayout($this->layout),
            'locales' => $meta['locales'],
            'primary_locale' => $meta['primary'],
            'nav' => ['links' => $resolver->buildNav($sections, $meta['locales'], $meta['primary'])],
            'sections' => $sections,
        ];
    }
}

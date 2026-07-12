<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Services\LandingResolver;
use App\Modules\Tenancy\Support\LandingSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Teacher's editable landing state (LANDING_CONTRACT_V2.md authoring shape):
 * layout + ALL sections (incl. hidden) with content, and `config` (not resolved
 * `items`) for dynamic sections, so the editor renders controls not preview data.
 *
 * @mixin TeacherProfile
 */
class TeacherLandingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $sections = $this->landing_sections ?: LandingSchema::defaults();
        $resolver = app(LandingResolver::class);

        return [
            'layout' => $resolver->normalizeLayout($this->layout),
            'nav' => ['links' => $resolver->buildNav($sections)],
            'sections' => $sections,
        ];
    }
}

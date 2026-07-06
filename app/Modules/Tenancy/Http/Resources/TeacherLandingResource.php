<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\TeacherProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TeacherProfile
 */
class TeacherLandingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'landing_sections' => $this->landing_sections ?? [],
        ];
    }
}

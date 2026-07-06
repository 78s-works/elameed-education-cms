<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\TeacherProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TeacherProfile
 */
class TeacherProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'logo_url' => $this->logo_url,
            'cover_url' => $this->cover_url,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'bio' => $this->bio,
            'contact' => $this->contact ?? (object) [],
            'socials' => $this->socials ?? (object) [],
        ];
    }
}

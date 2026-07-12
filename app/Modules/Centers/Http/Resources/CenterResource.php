<?php

namespace App\Modules\Centers\Http\Resources;

use App\Modules\Centers\Models\Center;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Center */
class CenterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'address' => $this->address,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
        ];
    }
}

<?php

namespace App\Modules\Wallet\Http\Resources;

use App\Modules\Wallet\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LedgerEntry
 */
class LedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'account' => $this->account,
            'direction' => $this->direction,
            'amount_minor' => $this->amount_minor,
            'ref_type' => $this->ref_type,
            'ref_id' => $this->ref_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Models\PackageItem;
use App\Modules\Catalog\Services\PackageItemService;
use App\Modules\Catalog\Support\AccessTerms;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry inside a package (VD change set §8.4). Carries the row (id, type,
 * order) plus a resolved summary of its target lesson or sub-package, so the
 * authoring UI renders the tree without a second round-trip. The target is
 * resolved under the active tenant + academic-year scope; a vanished target
 * yields `item: null`.
 *
 * @mixin PackageItem
 */
class PackageItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_type' => $this->item_type,
            'item_id' => $this->item_id,
            'sort_order' => $this->sort_order,
            'item' => $this->summary(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function summary(): ?array
    {
        if ($this->item_type === PackageItem::TYPE_LESSON) {
            $lesson = Lesson::find($this->item_id);

            return $lesson === null ? null : [
                'id' => $lesson->id,
                'type' => 'lesson',
                'name' => $lesson->title,
                'access_mode' => $lesson->access_mode?->value,
                'price_minor' => $lesson->price_minor,
                'currency' => $lesson->currency,
                'is_purchasable' => (bool) $lesson->is_purchasable,
                // How long access lasts once opened — a material term of sale, so
                // the student reads it in the package tree, before buying.
                'access_terms' => AccessTerms::forLesson($lesson),
            ];
        }

        $package = Package::find($this->item_id);

        return $package === null ? null : [
            'id' => $package->id,
            // uuid lets the student modal lazy-load a sub-package's own items on expand.
            'uuid' => $package->uuid,
            'type' => 'package',
            'name' => $package->name,
            'access_mode' => $package->access_mode?->value,
            'price_minor' => $package->price_minor,
            'currency' => $package->currency,
            'is_purchasable' => (bool) $package->is_purchasable,
            'items_count' => $package->items()->count(),
            // A sub-package row is a browsable node in the buy tree, so it states
            // its own terms too — folded recursively over ITS descendants. Without
            // this the tree showed windows on lesson rows and a blank on every
            // nested package.
            'access_terms' => AccessTerms::forPackage($package, app(PackageItemService::class)),
        ];
    }
}

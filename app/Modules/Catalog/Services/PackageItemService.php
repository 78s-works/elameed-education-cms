<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\AccessMode;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Models\PackageItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Attach / detach / reorder items on a recursive {@see Package}, enforcing the
 * three VD guards (doc 12 §7.6, doc 13 Phase 5) on every attach:
 *
 *   1. same-year — child and package share `academic_year_id` (LP-10).
 *   2. subset    — child.access_mode ⊆ package.access_mode (LP-5 / LP-D1).
 *   3. cycle     — attaching a sub-package must not create a loop (LP-2): the
 *                  target's descendant packages must not already contain this one.
 *
 * Every violation throws a 422 (validation_error envelope) keyed on `item_id`.
 * `descendantLessonIds()` is the depth-first walk a later checkout uses to fan a
 * package purchase out into per-lesson enrollments (LP-D2).
 */
class PackageItemService
{
    /**
     * Attach a lesson or sub-package to $package. $itemId is the child's internal
     * id (lessons carry no uuid; package_items.item_id is a bigint), resolved
     * within the tenant but across years so a cross-year attach yields a clear
     * "different academic year" message rather than a bare not-found.
     */
    public function attach(Package $package, string $itemType, int $itemId): PackageItem
    {
        $child = $this->resolveChild($itemType, $itemId);

        if ($child->academic_year_id !== $package->academic_year_id) {
            $this->reject('This item belongs to a different academic year.');
        }

        if (! $child->access_mode->isSubsetOf($package->access_mode)) {
            $this->reject(sprintf(
                'Item access_mode (%s) exceeds package access_mode (%s).',
                $child->access_mode->value,
                $package->access_mode->value,
            ));
        }

        if ($child instanceof Package) {
            $this->assertNoCycle($package, $child);
        }

        if ($package->items()->where('item_type', $itemType)->where('item_id', $itemId)->exists()) {
            $this->reject('This item is already in the package.');
        }

        return $package->items()->create([
            'item_type' => $itemType,
            'item_id' => $itemId,
            'sort_order' => $this->nextSortOrder($package),
        ]);
    }

    /** Remove one item from the package. The referenced lesson/package is untouched. */
    public function detach(Package $package, PackageItem $item): void
    {
        abort_unless($item->package_id === $package->id, 404);

        $item->delete();
    }

    /**
     * Reorder the package's items: sort_order = position in $order. Every id must
     * belong to this package; a foreign id is a 422.
     *
     * @param  array<int, int>  $order
     */
    public function reorder(Package $package, array $order): void
    {
        $ownIds = $package->items()->pluck('id')->all();

        foreach ($order as $id) {
            if (! in_array($id, $ownIds, true)) {
                throw ValidationException::withMessages([
                    'order' => "Item {$id} does not belong to this package.",
                ]);
            }
        }

        foreach (array_values($order) as $position => $id) {
            $package->items()->whereKey($id)->update(['sort_order' => $position]);
        }
    }

    /**
     * When a package narrows its own access_mode, every existing child must still
     * fit the new ceiling; otherwise reject with a 422 naming the offending items.
     */
    public function assertNarrowingAllowed(Package $package, AccessMode $newMode): void
    {
        $offending = [];

        foreach ($package->items()->get() as $item) {
            $child = $package->resolveItem($item);

            if ($child !== null && ! $child->access_mode->isSubsetOf($newMode)) {
                $offending[] = [
                    'id' => $child->id,
                    'type' => $item->item_type,
                    'name' => $item->item_type === PackageItem::TYPE_LESSON ? $child->title : $child->name,
                    'access_mode' => $child->access_mode->value,
                ];
            }
        }

        if ($offending !== []) {
            throw ValidationException::withMessages([
                'access_mode' => [sprintf(
                    'Cannot narrow the package to %s: %d item(s) have a wider access_mode.',
                    $newMode->value,
                    count($offending),
                )],
                'offending_items' => $offending,
            ]);
        }
    }

    /**
     * Depth-first collection of every descendant lesson id — the package's direct
     * lessons plus every lesson inside every sub-package, recursively (LP-D2). The
     * `$seen` set makes it safe even if the data somehow holds a cycle.
     *
     * Queried scope-free but pinned to the package's own tenant, so it returns the
     * same result whether called from the tenant-scoped authoring request or from a
     * checkout webhook where no tenant is resolved (the fan-out path, B15).
     *
     * @return Collection<int, int>
     */
    public function descendantLessonIds(Package $package): Collection
    {
        $lessonIds = collect();
        $stack = [$package->id];
        $seen = [];

        while ($stack !== []) {
            $current = array_pop($stack);

            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;

            foreach ($this->itemsOf($package->tenant_id, $current) as $item) {
                if ($item->item_type === PackageItem::TYPE_LESSON) {
                    $lessonIds->push((int) $item->item_id);
                } else {
                    $stack[] = (int) $item->item_id;
                }
            }
        }

        return $lessonIds->unique()->values();
    }

    /**
     * ORDERED depth-first walk of every descendant lesson id — direct lessons and
     * lessons inside sub-packages — following `package_items.sort_order` (then id)
     * at each level (B14 / VD R5 §7.5). Unlike {@see descendantLessonIds} (a set,
     * order-agnostic, used by the checkout fan-out), the ORDER matters here: it is
     * the exact sequence the sequential-unlock engine advances through. `$seen`
     * makes it cycle-safe; a lesson reachable twice keeps its first position.
     *
     * @return Collection<int, int>
     */
    public function orderedLessonIds(Package $package): Collection
    {
        $lessonIds = [];
        $this->walkOrdered((int) $package->tenant_id, (int) $package->id, $lessonIds, []);

        return collect($lessonIds)->unique()->values();
    }

    /**
     * @param  array<int, int>  $lessonIds  accumulated in sequence (by reference)
     * @param  array<int, bool>  $seen  packages already walked (cycle guard)
     */
    private function walkOrdered(int $tenantId, int $packageId, array &$lessonIds, array $seen): void
    {
        if (isset($seen[$packageId])) {
            return;
        }
        $seen[$packageId] = true;

        $items = PackageItem::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('package_id', $packageId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            if ($item->item_type === PackageItem::TYPE_LESSON) {
                $lessonIds[] = (int) $item->item_id;
            } else {
                $this->walkOrdered($tenantId, (int) $item->item_id, $lessonIds, $seen);
            }
        }
    }

    /** Scope-free package_items lookup, pinned to one tenant (webhook-safe). */
    private function itemsOf(int $tenantId, int $packageId): Collection
    {
        return PackageItem::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('package_id', $packageId)
            ->get();
    }

    /**
     * Every sub-package reachable from $package (its descendants, recursively).
     * Used by the cycle-guard.
     *
     * @return Collection<int, int>
     */
    public function descendantPackageIds(Package $package): Collection
    {
        $ids = collect();
        $stack = [$package->id];
        $seen = [];

        while ($stack !== []) {
            $current = array_pop($stack);

            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;

            $childPackageIds = PackageItem::where('package_id', $current)
                ->where('item_type', PackageItem::TYPE_PACKAGE)
                ->pluck('item_id');

            foreach ($childPackageIds as $childId) {
                $ids->push((int) $childId);
                $stack[] = (int) $childId;
            }
        }

        return $ids->unique()->values();
    }

    /** Resolve $itemId to a Lesson or Package in the tenant (year-agnostic), or 422. */
    private function resolveChild(string $itemType, int $itemId): Lesson|Package
    {
        $child = $itemType === PackageItem::TYPE_LESSON
            ? Lesson::withoutGlobalScope('academic_year')->find($itemId)
            : Package::withoutGlobalScope('academic_year')->find($itemId);

        if ($child === null) {
            $this->reject($itemType === PackageItem::TYPE_LESSON
                ? 'No such lesson.'
                : 'No such package.');
        }

        return $child;
    }

    /**
     * Attaching $child under $package loops if $child *is* $package or if $package
     * is already one of $child's descendants (…$package ⊃ … ⊃ $child ⊃ … ⊃ $package).
     */
    private function assertNoCycle(Package $package, Package $child): void
    {
        if ($child->id === $package->id || $this->descendantPackageIds($child)->contains($package->id)) {
            $this->reject('Attaching this package would create a cycle.');
        }
    }

    private function nextSortOrder(Package $package): int
    {
        return $package->items()->exists()
            ? ((int) $package->items()->max('sort_order')) + 1
            : 0;
    }

    /** Throw the standard attach-guard 422 (validation_error envelope, key item_id). */
    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['item_id' => $message]);
    }
}

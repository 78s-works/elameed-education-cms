<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\Course;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Prices a cart server-side (never trusts client prices — 04_API_Spec §4) and
 * persists orders. Supports single-course purchase, package (bundle) purchase,
 * and wallet top-up. Coupons are P1.5.
 */
class CheckoutService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{lines: array<int, array<string, mixed>>, total_minor: int, currency: string}
     */
    public function price(array $items): array
    {
        $lines = [];
        $total = 0;

        foreach ($items as $item) {
            $line = match ($item['type']) {
                OrderItem::TYPE_COURSE => $this->priceCourse($item),
                OrderItem::TYPE_BUNDLE => $this->priceBundle($item),
                OrderItem::TYPE_WALLET_TOPUP => $this->priceTopup($item),
                default => throw ValidationException::withMessages(['items' => 'Unsupported item type.']),
            };
            $total += $line['price_minor'];
            $lines[] = $line;
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'The cart is empty.']);
        }

        return ['lines' => $lines, 'total_minor' => $total, 'currency' => config('commerce.currency', 'EGP')];
    }

    public function createOrder(int $userId, array $items): Order
    {
        $quote = $this->price($items);

        $order = new Order([
            'user_id' => $userId,
            'total_minor' => $quote['total_minor'],
            'currency' => $quote['currency'],
        ]);
        $order->tenant_id = $this->context->tenantOrFail()->getKey();
        $order->save();

        foreach ($quote['lines'] as $line) {
            $order->items()->create($line);
        }

        return $order;
    }

    private function priceCourse(array $item): array
    {
        $course = Course::query()->where('uuid', $item['course'] ?? null)->first();

        if ($course === null || ! $course->purchase_enabled) {
            throw ValidationException::withMessages(['items' => 'Course not available for purchase.']);
        }

        return [
            'item_type' => OrderItem::TYPE_COURSE,
            'item_id' => $course->id,
            'price_minor' => $course->is_free ? 0 : (int) $course->price_minor,
            'title' => $course->title,
        ];
    }

    private function priceBundle(array $item): array
    {
        $bundle = Bundle::query()->where('uuid', $item['bundle'] ?? null)->first();

        if ($bundle === null || ! $bundle->purchase_enabled) {
            throw ValidationException::withMessages(['items' => 'Package not available for purchase.']);
        }

        return [
            'item_type' => OrderItem::TYPE_BUNDLE,
            'item_id' => $bundle->id,
            'price_minor' => $bundle->is_free ? 0 : (int) $bundle->price_minor,
            'title' => $bundle->title,
        ];
    }

    private function priceTopup(array $item): array
    {
        $amount = (int) ($item['amount_minor'] ?? 0);

        if ($amount < (int) config('commerce.min_topup_minor', 1000)) {
            throw ValidationException::withMessages(['items' => 'Top-up amount is below the minimum.']);
        }

        return [
            'item_type' => OrderItem::TYPE_WALLET_TOPUP,
            'item_id' => null,
            'price_minor' => $amount,
            'title' => 'Wallet top-up',
        ];
    }
}

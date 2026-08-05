<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Commerce\Models\Coupon;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Prices a cart server-side (never trusts client prices — 04_API_Spec §4) and
 * persists orders. Supports single-course purchase, single-lesson purchase,
 * wallet top-up, and an optional coupon discount (M21) applied to the content
 * subtotal. (Bundle purchase retired — Bundle removed, VD §7; recursive-package
 * checkout is a later doc.)
 */
class CheckoutService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CouponService $coupons,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{lines: array<int, array<string, mixed>>, subtotal_minor: int, discount_minor: int, total_minor: int, currency: string, coupon: ?Coupon}
     */
    public function price(array $items, ?string $couponCode = null): array
    {
        $lines = [];
        $subtotal = 0;

        foreach ($items as $item) {
            $line = match ($item['type']) {
                OrderItem::TYPE_COURSE => $this->priceCourse($item),
                OrderItem::TYPE_LESSON => $this->priceLesson($item),
                OrderItem::TYPE_WALLET_TOPUP => $this->priceTopup($item),
                default => throw ValidationException::withMessages(['items' => 'Unsupported item type.']),
            };
            $subtotal += $line['price_minor'];
            $lines[] = $line;
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'The cart is empty.']);
        }

        $discount = 0;
        $coupon = null;

        if ($couponCode !== null && $couponCode !== '') {
            $applied = $this->coupons->apply($couponCode, $lines);
            $coupon = $applied['coupon'];
            $discount = $applied['discount_minor'];
        }

        return [
            'lines' => $lines,
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discount,
            'total_minor' => max(0, $subtotal - $discount),
            'currency' => config('commerce.currency', 'EGP'),
            'coupon' => $coupon,
        ];
    }

    public function createOrder(int $userId, array $items, ?string $couponCode = null): Order
    {
        $quote = $this->price($items, $couponCode);

        // Don't let a student buy content they already own (M04). Checked at the
        // order step so the wallet isn't charged for a duplicate enrollment.
        foreach ($quote['lines'] as $line) {
            $this->assertNotAlreadyOwned($userId, $line);
        }

        $order = new Order([
            'user_id' => $userId,
            'total_minor' => $quote['total_minor'],
            'subtotal_minor' => $quote['subtotal_minor'],
            'discount_minor' => $quote['discount_minor'],
            'currency' => $quote['currency'],
            'coupon_id' => $quote['coupon']?->getKey(),
        ]);
        $order->tenant_id = $this->context->tenantOrFail()->getKey();
        $order->save();

        foreach ($quote['lines'] as $line) {
            $order->items()->create($line);
        }

        return $order;
    }

    /** Reject an order line for content the buyer already has access to. */
    private function assertNotAlreadyOwned(int $userId, array $line): void
    {
        $column = match ($line['item_type']) {
            OrderItem::TYPE_COURSE => 'course_id',
            OrderItem::TYPE_LESSON => 'lesson_id',
            default => null, // wallet top-up: nothing to dedupe
        };
        if ($column === null) {
            return;
        }

        $owns = Enrollment::query()
            ->where('user_id', $userId)
            ->where($column, $line['item_id'])
            ->grantsAccess()
            ->exists();

        if ($owns) {
            throw ValidationException::withMessages(['items' => 'You already have access to one of these items.']);
        }
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

    private function priceLesson(array $item): array
    {
        $lesson = Lesson::query()->find($item['lesson'] ?? null);

        if ($lesson === null || ! $lesson->is_purchasable) {
            throw ValidationException::withMessages(['items' => 'Lesson not available for purchase.']);
        }

        return [
            'item_type' => OrderItem::TYPE_LESSON,
            'item_id' => $lesson->id,
            'price_minor' => (int) $lesson->price_minor,
            'title' => $lesson->title,
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

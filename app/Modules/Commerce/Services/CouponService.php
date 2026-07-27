<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\Coupon;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Validates a coupon against a priced cart and computes the discount (M21). The
 * lookup is tenant-scoped (BelongsToTenant), so a code only resolves within the
 * academy that issued it. Discounts apply to CONTENT lines (courses/bundles) —
 * never wallet top-ups — and the teacher absorbs them at fulfilment.
 */
class CouponService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<int, array<string, mixed>>  $lines  priced lines from CheckoutService::price()
     * @return array{coupon: Coupon, discount_minor: int}
     *
     * @throws ValidationException when the code is unknown, expired, used up, or
     *                             does not apply to the cart.
     */
    public function apply(string $code, array $lines): array
    {
        $coupon = Coupon::query()->where('code', $code)->first();

        if ($coupon === null || ! $coupon->isRedeemable()) {
            throw ValidationException::withMessages(['coupon' => 'This coupon is not valid.']);
        }

        $base = $this->applicableBase($coupon, $lines);

        if ($base <= 0) {
            throw ValidationException::withMessages(['coupon' => 'This coupon does not apply to the items in your cart.']);
        }

        if ($coupon->min_subtotal_minor !== null && $this->contentSubtotal($lines) < $coupon->min_subtotal_minor) {
            throw ValidationException::withMessages(['coupon' => 'Your cart has not reached this coupon\'s minimum.']);
        }

        return ['coupon' => $coupon, 'discount_minor' => $coupon->discountFor($base)];
    }

    /** Sum of content (course + bundle) line prices; top-ups are never discountable. */
    private function contentSubtotal(array $lines): int
    {
        $sum = 0;

        foreach ($lines as $line) {
            if (in_array($line['item_type'], [OrderItem::TYPE_COURSE, OrderItem::TYPE_BUNDLE], true)) {
                $sum += (int) $line['price_minor'];
            }
        }

        return $sum;
    }

    /** The base the coupon discounts against: a scoped course's price, or the whole content subtotal. */
    private function applicableBase(Coupon $coupon, array $lines): int
    {
        if ($coupon->course_id === null) {
            return $this->contentSubtotal($lines);
        }

        $sum = 0;

        foreach ($lines as $line) {
            if ($line['item_type'] === OrderItem::TYPE_COURSE && (int) $line['item_id'] === (int) $coupon->course_id) {
                $sum += (int) $line['price_minor'];
            }
        }

        return $sum;
    }
}

<?php

namespace App\Modules\Commerce\Enums;

/** How a coupon's `value` is interpreted (M21). */
enum CouponType: string
{
    case Percent = 'percent'; // value = 1..100 (% off the applicable base)
    case Fixed = 'fixed';     // value = minor units off the applicable base
}

<?php

namespace App\Modules\Billing\Enums;

/** Recurring billing cadence for a subscription package (M03). */
enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
}

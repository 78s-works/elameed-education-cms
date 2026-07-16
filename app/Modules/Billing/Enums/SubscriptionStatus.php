<?php

namespace App\Modules\Billing\Enums;

/**
 * Lifecycle of a teacher's subscription (M03). `trialing`/`active` are the
 * "current" states that grant an operational plan; `past_due` is a grace state
 * pending payment; `canceled`/`expired` are terminal.
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Expired = 'expired';

    /** Does this status grant the teacher an operational plan right now? */
    public function isCurrent(): bool
    {
        return $this === self::Trialing || $this === self::Active;
    }
}

<?php

namespace App\Modules\Commerce\Enums;

/**
 * The unified payment method / source of one sale row (sales ledger).
 *
 * The raw data spreads a sale's origin across three tables — `payments.gateway`
 * for gateway checkouts, `orders` (a wallet-funded checkout has no gateway) and
 * `enrollments.source` for grants that never went through checkout (activation
 * code, staff grant, center attendance). This enum is the single normalized
 * value the ledger filters and groups by; the derivation lives in
 * {@see \App\Modules\Reporting\Services\SalesLedgerQuery}.
 *
 * Note on manual receipts (Vodafone Cash / InstaPay): an approved receipt tops
 * up the student's WALLET — it never buys content directly. So a purchase funded
 * that way reports as `Wallet` here, and the receipt itself shows up in the
 * wallet top-ups view. `Manual` is a staff-issued access grant (no money moved
 * through the platform).
 */
enum SalesMethod: string
{
    /** Fawry gateway — value reserved; goes live with the gateway. */
    case Fawry = 'fawry';

    /** Card via Paymob. */
    case CardPaymob = 'card_paymob';

    /** Paid from the student's wallet balance. */
    case Wallet = 'wallet';

    /** Content activation code redeemed (M12). */
    case Code = 'code';

    /** Staff granted the access by hand. */
    case Manual = 'manual';

    /** Enrolled at a physical center (attendance). */
    case Center = 'center';

    /** The item's price was 0. */
    case Free = 'free';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Fawry => 'Fawry',
            self::CardPaymob => 'Card (Paymob)',
            self::Wallet => 'Wallet',
            self::Code => 'Activation code',
            self::Manual => 'Manual grant',
            self::Center => 'Center',
            self::Free => 'Free',
        };
    }
}

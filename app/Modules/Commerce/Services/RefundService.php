<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Services\PackageItemService;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Modules\Commerce\Enums\OrderStatus;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Commerce\Models\Refund;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reverses a paid order (M17 sales ledger). One call does four things, in one
 * transaction: posts the balanced reversal to the ledger, records the `refunds`
 * row, revokes the access the order bought (full refunds only), and flips the
 * order to `refunded`.
 *
 * Money direction: the refund credits the student's WALLET. Gateway refund APIs
 * are not wired (Paymob is stubbed, Fawry not live), so pushing money back to
 * the card is not something we can promise; crediting the wallet is immediate,
 * auditable and spendable. `destination = offline` records a refund settled in
 * cash outside the platform — the books move, the wallet does not.
 *
 * Partial refunds are allowed and additive: the order stays `paid` until the
 * refunded total reaches the order total, and the sales ledger always subtracts
 * SUM(refunds.amount_minor), never a whole row.
 */
class RefundService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly PackageItemService $packageItems,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /** Already-refunded total for the order, in minor units. */
    public function refundedTotal(Order $order): int
    {
        return (int) Refund::query()->where('order_id', $order->getKey())->sum('amount_minor');
    }

    /** What is still refundable on the order, in minor units. */
    public function refundable(Order $order): int
    {
        return max(0, (int) $order->total_minor - $this->refundedTotal($order));
    }

    /**
     * @param  int|null  $amountMinor  null → refund everything still refundable
     * @param  string  $destination  Refund::TO_WALLET | Refund::TO_OFFLINE
     */
    public function refund(
        Order $order,
        ?int $amountMinor = null,
        ?string $reason = null,
        string $destination = Refund::TO_WALLET,
        ?int $actorId = null,
    ): Refund {
        if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::Refunded], true)) {
            throw ValidationException::withMessages([
                'order' => __('Only a paid order can be refunded.'),
            ]);
        }

        $refundable = $this->refundable($order);
        if ($refundable <= 0) {
            throw ValidationException::withMessages([
                'order' => __('This order has already been fully refunded.'),
            ]);
        }

        $amount = $amountMinor ?? $refundable;
        if ($amount <= 0 || $amount > $refundable) {
            throw ValidationException::withMessages([
                'amount_minor' => __('The refund amount must be between 1 and the refundable remainder.'),
            ]);
        }

        $tenantId = (int) $order->tenant_id;
        $isFull = $amount === $refundable;

        $refund = DB::transaction(function () use ($order, $tenantId, $amount, $reason, $destination, $actorId, $isFull): Refund {
            // Sequence number so a second (partial) refund on the same order gets
            // its own idempotency key instead of being swallowed as a replay.
            $seq = Refund::query()->where('order_id', $order->getKey())->count() + 1;

            $this->ledger->post(
                $tenantId,
                "order:{$order->id}:refund:{$seq}",
                $this->legs($tenantId, $order, $amount, $destination),
                'refund',
                (int) $order->id,
            );

            $refund = new Refund([
                'order_id' => $order->getKey(),
                'amount_minor' => $amount,
                'currency' => $order->currency ?? 'EGP',
                'destination' => $destination,
                'reason' => $reason,
                'revoked_access' => $isFull,
                'refunded_by' => $actorId,
            ]);
            $refund->tenant_id = $tenantId;
            $refund->save();

            // A partial refund is a price correction, not a cancellation — access
            // survives. Only a full refund takes back what the order granted.
            if ($isFull) {
                $this->revokeAccess($order);
                $order->update(['status' => OrderStatus::Refunded->value]);
            }

            return $refund;
        });

        $this->notifications->inApp($tenantId, (int) $order->user_id, 'purchase.refunded', [
            'order_uuid' => $order->uuid,
            'amount_minor' => $amount,
            'destination' => $destination,
            'full' => $isFull,
        ]);

        // A refund is the most financially sensitive action in the system, so
        // the actor is passed explicitly rather than left to Auth::id() — this
        // runs from queued and webhook contexts where no user is authenticated
        // but the caller knows who ordered it.
        $this->audit->log('order.refunded', [
            'order_uuid' => $order->uuid,
            'amount_minor' => $amount,
            'destination' => $destination,
            'full' => $isFull,
            'reason' => $reason,
        ], $tenantId, 'order', (int) $order->id, $actorId);

        return $refund;
    }

    /**
     * The reversal legs. The credit side is where the money goes (the student's
     * wallet, or — for an offline settlement — back to gateway_clearing so the
     * post still balances). The debit side takes the revenue off the teacher and
     * its commission share off the platform, the same split
     * {@see FulfillOrderService} applied on the way in.
     *
     * @return list<array{account: string, direction: string, amount_minor: int, wallet_id: int|null}>
     */
    private function legs(int $tenantId, Order $order, int $amount, string $destination): array
    {
        $commission = (int) floor($amount * (float) config('commerce.commission_percent', 0) / 100);

        $legs = [[
            'account' => LedgerEntry::TEACHER_EARNINGS,
            'direction' => LedgerEntry::DEBIT,
            'amount_minor' => $amount - $commission,
            'wallet_id' => null,
        ]];

        if ($commission > 0) {
            $legs[] = [
                'account' => LedgerEntry::PLATFORM_COMMISSION,
                'direction' => LedgerEntry::DEBIT,
                'amount_minor' => $commission,
                'wallet_id' => null,
            ];
        }

        if ($destination === Refund::TO_WALLET) {
            $wallet = $this->ledger->walletFor($tenantId, (int) $order->user_id);
            $legs[] = [
                'account' => LedgerEntry::STUDENT_WALLET,
                'direction' => LedgerEntry::CREDIT,
                'amount_minor' => $amount,
                'wallet_id' => $wallet->id,
            ];
        } else {
            $legs[] = [
                'account' => LedgerEntry::GATEWAY_CLEARING,
                'direction' => LedgerEntry::CREDIT,
                'amount_minor' => $amount,
                'wallet_id' => null,
            ];
        }

        return $legs;
    }

    /**
     * Cancels the enrollments this order granted. Enrollments carry no order id,
     * so the match is by buyer + the lessons the order's items resolve to (a
     * package item fans out into its descendant lessons, the same way the
     * purchase did). Cancelled, not deleted: the grant history stays, and a
     * later re-purchase knows the student had — and lost — this lesson.
     */
    private function revokeAccess(Order $order): void
    {
        $lessonIds = [];

        foreach ($order->items as $item) {
            if ($item->item_type === OrderItem::TYPE_LESSON && $item->item_id !== null) {
                $lessonIds[] = (int) $item->item_id;
            } elseif ($item->item_type === OrderItem::TYPE_PACKAGE && $item->item_id !== null) {
                $package = Package::withoutGlobalScopes()->find($item->item_id);
                if ($package !== null) {
                    $lessonIds = array_merge(
                        $lessonIds,
                        $this->packageItems->descendantLessonIds($package)->all(),
                    );
                }
            }
        }

        $lessonIds = array_values(array_unique($lessonIds));
        if ($lessonIds === []) {
            return;
        }

        Enrollment::withoutGlobalScopes()
            ->where('tenant_id', (int) $order->tenant_id)
            ->where('user_id', (int) $order->user_id)
            ->whereIn('lesson_id', $lessonIds)
            ->where('status', EnrollmentStatus::Active->value)
            ->update(['status' => EnrollmentStatus::Cancelled->value, 'updated_at' => now()]);
    }
}

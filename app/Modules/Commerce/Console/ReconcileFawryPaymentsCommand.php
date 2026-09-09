<?php

namespace App\Modules\Commerce\Console;

use App\Modules\Commerce\Gateways\FawryGateway;
use App\Modules\Commerce\Models\Payment;
use App\Modules\Commerce\Services\FulfillOrderService;
use App\Modules\Wallet\Models\LedgerEntry;
use Illuminate\Console\Command;

/**
 * Fawry reconciliation (EDU-018). A student pays cash at an outlet and the
 * notification is the only thing that tells us — so when one is lost, the money
 * is collected and the lesson never unlocks. This asks Fawry directly about
 * every reference still pending and settles what it finds:
 *
 *   PAID     → record the payment and fulfil the order (same idempotent path as
 *              the webhook: a later notification changes nothing).
 *   EXPIRED  → close the reference; nothing is granted.
 *
 * Anything Fawry has no answer for is left alone for the next run.
 */
class ReconcileFawryPaymentsCommand extends Command
{
    protected $signature = 'fawry:reconcile {--limit=200 : How many pending references to check in one run}';

    protected $description = 'Settle Fawry references whose payment notification never arrived';

    public function handle(FawryGateway $gateway, FulfillOrderService $fulfiller): int
    {
        $pending = Payment::withoutGlobalScopes()
            ->where('gateway', 'fawry')
            ->where('status', Payment::STATUS_PENDING)
            ->whereNotNull('reference_number')
            ->with('order.items')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $paid = 0;
        $expired = 0;

        foreach ($pending as $payment) {
            $status = $gateway->paymentStatus((string) $payment->reference_number);

            // No answer from Fawry: leave it pending unless the reference itself
            // has lapsed — an expiry we can read off our own clock.
            if ($status === null || $status['status'] === 'unknown' || $status['status'] === 'pending') {
                if ($payment->expires_at !== null && $payment->expires_at->isPast()) {
                    $payment->update(['status' => Payment::STATUS_EXPIRED, 'processed_at' => now()]);
                    $expired++;
                }

                continue;
            }

            if ($status['status'] === 'expired') {
                $payment->update(['status' => Payment::STATUS_EXPIRED, 'processed_at' => now()]);
                $expired++;

                continue;
            }

            $order = $payment->order;

            if ($order === null) {
                continue;
            }

            $payment->update([
                'gateway_txn_id' => $status['gateway_txn_id'] ?: $payment->gateway_txn_id,
                'amount_minor' => $status['amount_minor'] ?: $payment->amount_minor,
                'status' => Payment::STATUS_PAID,
                'processed_at' => now(),
            ]);

            $fulfiller->fulfill($order, LedgerEntry::GATEWAY_CLEARING);
            $paid++;
        }

        $this->info(sprintf('Fawry reconciliation — checked: %d, settled: %d, expired: %d.', $pending->count(), $paid, $expired));

        return self::SUCCESS;
    }
}

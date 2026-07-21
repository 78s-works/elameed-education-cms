<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\Course;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Enums\OrderStatus;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use Illuminate\Support\Facades\DB;

/**
 * Turns a funded order into: balanced ledger postings, course enrollments, an
 * invoice, and a notification. Idempotent — safe to call from a wallet payment
 * OR a (possibly replayed) gateway webhook. The ledger post + invoice + "already
 * paid" checks each dedupe independently (02_Architecture.md §8).
 *
 * @param  string  $funding  one of LedgerEntry::STUDENT_WALLET | GATEWAY_CLEARING
 */
class FulfillOrderService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly EnrollmentService $enrollments,
        private readonly InvoiceService $invoices,
        private readonly NotificationService $notifications,
    ) {}

    public function fulfill(Order $order, string $funding): void
    {
        if ($order->status === OrderStatus::Paid) {
            return; // already fulfilled
        }

        $tenantId = (int) $order->tenant_id;
        $wallet = $this->ledger->walletFor($tenantId, (int) $order->user_id);

        $legs = [];
        $contentTotal = 0;
        $courseIds = [];
        $bundleIds = [];

        foreach ($order->items as $item) {
            if ($item->item_type === OrderItem::TYPE_COURSE) {
                $contentTotal += (int) $item->price_minor;
                $courseIds[] = (int) $item->item_id;
            } elseif ($item->item_type === OrderItem::TYPE_BUNDLE) {
                $contentTotal += (int) $item->price_minor;
                $bundleIds[] = (int) $item->item_id;
            } elseif ($item->item_type === OrderItem::TYPE_WALLET_TOPUP) {
                // Money lands in the student's wallet.
                $legs[] = $this->leg(LedgerEntry::STUDENT_WALLET, LedgerEntry::CREDIT, (int) $item->price_minor, $wallet->id);
            }
        }

        // Split content revenue (courses + packages) between teacher earnings and
        // platform commission.
        if ($contentTotal > 0) {
            $commission = (int) floor($contentTotal * (float) config('commerce.commission_percent', 0) / 100);
            $legs[] = $this->leg(LedgerEntry::TEACHER_EARNINGS, LedgerEntry::CREDIT, $contentTotal - $commission);
            if ($commission > 0) {
                $legs[] = $this->leg(LedgerEntry::PLATFORM_COMMISSION, LedgerEntry::CREDIT, $commission);
            }
        }

        // Funding side (the money source), debited for the whole order total.
        $fundingWalletId = $funding === LedgerEntry::STUDENT_WALLET ? $wallet->id : null;
        $legs[] = $this->leg($funding, LedgerEntry::DEBIT, (int) $order->total_minor, $fundingWalletId);

        DB::transaction(function () use ($order, $tenantId, $legs, $courseIds, $bundleIds, $funding): void {
            $this->ledger->post($tenantId, "order:{$order->id}:fulfill", $legs, 'order', (int) $order->id);

            $source = $funding === LedgerEntry::STUDENT_WALLET ? EnrollmentSource::Wallet : EnrollmentSource::Purchase;
            foreach (array_unique($courseIds) as $courseId) {
                $course = Course::withoutGlobalScopes()->find($courseId);
                if ($course !== null) {
                    $this->enrollments->grantCourse($tenantId, (int) $order->user_id, $course, $source);
                }
            }

            // A package grants an enrollment for each course/unit it contains.
            foreach (array_unique($bundleIds) as $bundleId) {
                $bundle = Bundle::withoutGlobalScopes()->with('items')->find($bundleId);
                if ($bundle !== null) {
                    $this->enrollments->grantBundle($tenantId, (int) $order->user_id, $bundle, $source);
                }
            }

            $order->update(['status' => OrderStatus::Paid->value]);
            $this->invoices->issueFor($order);
        });

        $this->notifications->inApp($tenantId, (int) $order->user_id, 'purchase.completed', [
            'order_uuid' => $order->uuid,
            'total_minor' => (int) $order->total_minor,
        ]);
    }

    private function leg(string $account, string $direction, int $amount, ?int $walletId = null): array
    {
        return [
            'account' => $account,
            'direction' => $direction,
            'amount_minor' => $amount,
            'wallet_id' => $walletId,
        ];
    }
}

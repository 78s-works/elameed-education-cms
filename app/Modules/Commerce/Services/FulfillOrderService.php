<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
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
        private readonly InvoicePdfService $invoicePdf,
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
        $lessonIds = [];

        foreach ($order->items as $item) {
            if ($item->item_type === OrderItem::TYPE_COURSE) {
                $contentTotal += (int) $item->price_minor;
                $courseIds[] = (int) $item->item_id;
            } elseif ($item->item_type === OrderItem::TYPE_LESSON) {
                $contentTotal += (int) $item->price_minor;
                $lessonIds[] = (int) $item->item_id;
            } elseif ($item->item_type === OrderItem::TYPE_WALLET_TOPUP) {
                // Money lands in the student's wallet.
                $legs[] = $this->leg(LedgerEntry::STUDENT_WALLET, LedgerEntry::CREDIT, (int) $item->price_minor, $wallet->id);
            }
        }

        // A coupon discount (M21) is absorbed by the teacher's content revenue so
        // the ledger balances against the discounted order total.
        $contentTotal = max(0, $contentTotal - (int) $order->discount_minor);

        // Split content revenue (courses + lessons) between teacher earnings and
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

        DB::transaction(function () use ($order, $tenantId, $legs, $courseIds, $lessonIds, $funding): void {
            $this->ledger->post($tenantId, "order:{$order->id}:fulfill", $legs, 'order', (int) $order->id);

            $source = $funding === LedgerEntry::STUDENT_WALLET ? EnrollmentSource::Wallet : EnrollmentSource::Purchase;
            foreach (array_unique($courseIds) as $courseId) {
                $course = Course::withoutGlobalScopes()->find($courseId);
                if ($course !== null) {
                    $this->enrollments->grantCourse($tenantId, (int) $order->user_id, $course, $source);
                }
            }

            // A single lesson purchase (doc 11 R4 "pay lesson") — grants lesson
            // access and opens its time-boxed window (the "week" from payment).
            foreach (array_unique($lessonIds) as $lessonId) {
                $lesson = Lesson::withoutGlobalScopes()->find($lessonId);
                if ($lesson !== null) {
                    $this->enrollments->grantLesson($tenantId, (int) $order->user_id, $lesson, $source);
                }
            }

            // Count the coupon redemption once, at first fulfilment (this method
            // returns early on a replayed webhook, so it can't double-count).
            if ($order->coupon_id !== null) {
                DB::table('coupons')->where('id', $order->coupon_id)->increment('used_count');
            }

            $order->update(['status' => OrderStatus::Paid->value]);
            $this->invoices->issueFor($order);
        });

        // Render the invoice PDF outside the money transaction — best-effort, so a
        // rendering hiccup never rolls back a completed purchase. The download
        // endpoint also regenerates on demand if this is ever skipped.
        try {
            $invoice = $order->invoice()->first();
            if ($invoice !== null) {
                $this->invoicePdf->generate($invoice);
            }
        } catch (\Throwable $e) {
            report($e);
        }

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

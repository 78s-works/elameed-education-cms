<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\Invoice;
use App\Modules\Commerce\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Issues internal invoices with a gap-free sequential number per tenant
 * (03_Data_Model.md §5). ETA e-receipt is a final-phase concern.
 */
class InvoiceService
{
    public function issueFor(Order $order): Invoice
    {
        $existing = Invoice::withoutGlobalScopes()->where('order_id', $order->getKey())->first();

        if ($existing !== null) {
            return $existing; // idempotent — one invoice per order
        }

        return DB::transaction(function () use ($order): Invoice {
            // Lock the tenant's invoice rows to keep numbering gap-free.
            $last = Invoice::withoutGlobalScopes()
                ->where('tenant_id', $order->tenant_id)
                ->lockForUpdate()
                ->max('number');

            $invoice = new Invoice([
                'order_id' => $order->getKey(),
                'number' => ((int) $last) + 1,
                'issued_at' => now(),
            ]);
            $invoice->tenant_id = $order->tenant_id;
            $invoice->save();

            return $invoice;
        });
    }
}

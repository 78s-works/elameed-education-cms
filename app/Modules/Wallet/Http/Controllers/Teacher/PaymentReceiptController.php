<?php

namespace App\Modules\Wallet\Http\Controllers\Teacher;

use App\Modules\Wallet\Http\Requests\RejectReceiptRequest;
use App\Modules\Wallet\Http\Resources\PaymentReceiptResource;
use App\Modules\Wallet\Models\PaymentReceipt;
use App\Modules\Wallet\Services\PaymentReceiptService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * /teacher/payment-receipts (VD R10) — a teacher or `finance`-permitted assistant
 * reviews manual wallet top-ups. Tenant-level (not year-scoped). Route-model binding
 * on {receipt:uuid} is tenant-scoped by BelongsToTenant, so a cross-tenant uuid 404s.
 */
class PaymentReceiptController
{
    public function __construct(private readonly PaymentReceiptService $service) {}

    /** List receipts, newest first; defaults to the `pending` queue. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = (string) $request->query('status', PaymentReceipt::STATUS_PENDING);

        $query = PaymentReceipt::query()->with(['user', 'attachment'])->latest('id');

        if (in_array($status, [
            PaymentReceipt::STATUS_PENDING,
            PaymentReceipt::STATUS_APPROVED,
            PaymentReceipt::STATUS_REJECTED,
        ], true)) {
            $query->where('status', $status);
        }

        return PaymentReceiptResource::collection($query->paginate(30));
    }

    public function show(PaymentReceipt $receipt): PaymentReceiptResource
    {
        return new PaymentReceiptResource($receipt->load(['user', 'attachment', 'reviewer']));
    }

    public function approve(Request $request, PaymentReceipt $receipt): PaymentReceiptResource
    {
        $updated = $this->service->approve($receipt, $request->user());

        return new PaymentReceiptResource($updated->load(['user', 'attachment', 'reviewer']));
    }

    public function reject(RejectReceiptRequest $request, PaymentReceipt $receipt): PaymentReceiptResource
    {
        $updated = $this->service->reject($receipt, $request->user(), $request->validated('reason'));

        return new PaymentReceiptResource($updated->load(['user', 'attachment', 'reviewer']));
    }
}

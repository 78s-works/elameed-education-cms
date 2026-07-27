<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Modules\Commerce\Http\Resources\InvoiceResource;
use App\Modules\Commerce\Models\Invoice;
use App\Modules\Commerce\Services\InvoicePdfService;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /invoices (M06) — the buyer's own invoices, plus access-controlled detail and
 * a PDF download. A tenant teacher/assistant can also read/download any invoice
 * in their academy. Invoices bind by uuid and are tenant-scoped, so a valid uuid
 * from another tenant resolves to 404.
 */
class InvoiceController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly InvoicePdfService $pdf,
    ) {}

    /** The current user's invoices in this tenant. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->context->tenantOrFail();
        $userId = $request->user()->getKey();

        $invoices = Invoice::query()
            ->whereHas('order', fn ($q) => $q->where('user_id', $userId))
            ->with('order.items')
            ->latest('id')
            ->paginate(30);

        return InvoiceResource::collection($invoices);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeView($request, $invoice);
        $invoice->load('order.items');

        return response()->json(['data' => (new InvoiceResource($invoice))->resolve($request)]);
    }

    /** Stream the (access-controlled) PDF, rendering it on first request if needed. */
    public function download(Request $request, Invoice $invoice): Response
    {
        $this->authorizeView($request, $invoice);

        return response($this->pdf->contents($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="invoice-'.$invoice->number.'.pdf"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** The buyer, or an active teacher/assistant of the invoice's tenant. */
    private function authorizeView(Request $request, Invoice $invoice): void
    {
        $userId = (int) $request->user()->getKey();

        if ($invoice->buyerId() === $userId) {
            return;
        }

        $isStaff = TenantUser::withoutGlobalScopes()
            ->where('tenant_id', $invoice->tenant_id)
            ->where('user_id', $userId)
            ->whereIn('role', [TenantUserRole::Teacher->value, TenantUserRole::Assistant->value])
            ->where('status', MembershipStatus::Active->value)
            ->exists();

        abort_unless($isStaff, 403);
    }
}

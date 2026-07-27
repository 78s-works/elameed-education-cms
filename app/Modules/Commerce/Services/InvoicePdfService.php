<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\Invoice;
use App\Modules\Tenancy\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Renders an invoice to a PDF on a PRIVATE disk and records its path on
 * `invoices.pdf_url`. The file is never publicly served — it is streamed only
 * through the access-controlled download endpoint.
 */
class InvoicePdfService
{
    private function disk(): string
    {
        return (string) config('invoices.disk', 'local');
    }

    private function path(Invoice $invoice): string
    {
        return "invoices/{$invoice->tenant_id}/invoice-{$invoice->number}.pdf";
    }

    /** (Re)render + store the PDF; returns the stored disk path. */
    public function generate(Invoice $invoice): string
    {
        $invoice->loadMissing(['order.items', 'order.user']);
        $tenant = Tenant::withoutGlobalScopes()->find($invoice->tenant_id);

        $pdf = Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice,
            'order' => $invoice->order,
            'tenant' => $tenant,
            'buyer' => $invoice->order?->user,
        ])->setPaper('a4');

        $path = $this->path($invoice);
        Storage::disk($this->disk())->put($path, $pdf->output());

        $invoice->forceFill(['pdf_url' => $path])->saveQuietly();

        return $path;
    }

    /** Return the stored path, rendering on first request if it is missing. */
    public function ensure(Invoice $invoice): string
    {
        if ($invoice->hasPdf() && Storage::disk($this->disk())->exists((string) $invoice->pdf_url)) {
            return (string) $invoice->pdf_url;
        }

        return $this->generate($invoice);
    }

    public function contents(Invoice $invoice): string
    {
        return (string) Storage::disk($this->disk())->get($this->ensure($invoice));
    }
}

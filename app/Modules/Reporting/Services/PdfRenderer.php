<?php

namespace App\Modules\Reporting\Services;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders report PDFs with mPDF.
 *
 * WHY NOT DOMPDF, which this repo already has for invoices: dompdf does no
 * complex-text shaping and no bidi. Arabic comes out as isolated letter forms in
 * logical order — visually reversed and unjoined, i.e. unreadable — and no font
 * choice fixes it, because the shaping is the renderer's job. mPDF does Arabic
 * shaping and right-to-left layout natively, so an Arabic report reads the way
 * the screen shows it.
 *
 * The invoice PDF still goes through dompdf; migrating it is its own change with
 * its own visual review, not a side effect of the reports work.
 */
class PdfRenderer
{
    /**
     * Render a Blade view to PDF bytes.
     *
     * @param  array<string, mixed>  $data
     * @param  'ar'|'en'  $locale  drives page directionality
     */
    public function render(string $view, array $data, string $locale = 'ar', string $orientation = 'P'): string
    {
        $rtl = $locale === 'ar';

        // mPDF subsets the font it embeds, and an Arabic subset of DejaVu is
        // large: rendering needs a few hundred megabytes that a default 128M
        // php.ini does not have. The export runs in a queue worker, so raising
        // the ceiling here — for this call only — is safer than depending on
        // every host's php.ini being generous. Without it the process dies
        // outright rather than failing the job, which is how this first showed
        // up: the test passed alone and killed the suite.
        $previousLimit = ini_get('memory_limit');
        $this->raiseMemoryTo(512);

        try {
            return $this->build($view, $data, $rtl, $orientation);
        } finally {
            $this->restoreMemoryLimit($previousLimit === false ? null : (string) $previousLimit);
        }
    }

    /** @param array<string, mixed> $data */
    private function build(string $view, array $data, bool $rtl, string $orientation): string
    {

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $orientation === 'L' ? 'A4-L' : 'A4',
            // DejaVu carries the Arabic block, and mPDF supplies the shaping and
            // the bidi pass over it.
            'default_font' => 'dejavusans',
            'default_font_size' => 9,
            'margin_top' => 14,
            'margin_bottom' => 16,
            'margin_left' => 10,
            'margin_right' => 10,
            // mPDF writes its font subsets to disk; keep that inside storage
            // rather than in the vendor directory, which a deploy replaces.
            'tempDir' => $this->tempDir(),
        ]);

        if ($rtl) {
            // Sets both the paragraph direction and the page's column order, so
            // a table's first column starts at the right edge.
            $mpdf->SetDirectionality('rtl');
        }

        $mpdf->SetTitle((string) ($data['title'] ?? 'Report'));
        $mpdf->SetAuthor((string) ($data['academy'] ?? 'El Ameed'));
        // Footer needs the page number on every sheet: a printed report that
        // loses a page should show that it did.
        $mpdf->SetHTMLFooter(
            '<div style="text-align:center;font-size:7pt;color:#888;">'
            .($rtl ? 'صفحة {PAGENO} من {nbpg}' : 'Page {PAGENO} of {nbpg}')
            .'</div>'
        );

        $mpdf->WriteHTML(view($view, $data)->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * mPDF writes font subsets to disk. Keep that inside storage rather than the
     * vendor directory (a deploy replaces vendor), and create it on first use —
     * mPDF fatals rather than throwing when the directory is missing.
     */
    private function tempDir(): string
    {
        $dir = storage_path('app/mpdf');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /** Raise the limit if it is below what a render needs; never lower it. */
    private function raiseMemoryTo(int $megabytes): void
    {
        $current = (string) ini_get('memory_limit');

        if ($current === '-1') {
            return;
        }

        if ($this->toBytes($current) < $megabytes * 1024 * 1024) {
            ini_set('memory_limit', $megabytes.'M');
        }
    }

    /**
     * Put the previous ceiling back, but only if the process still fits under
     * it: PHP refuses to lower memory_limit below what is already allocated and
     * errors when asked to, which is a silly way to fail a render that just
     * succeeded. Leaving the raised limit in place costs nothing — it is a
     * ceiling, not a reservation.
     */
    private function restoreMemoryLimit(?string $previous): void
    {
        if ($previous === null || $previous === '-1') {
            return;
        }

        if ($this->toBytes($previous) > memory_get_usage(true)) {
            ini_set('memory_limit', $previous);
        }
    }

    private function toBytes(string $limit): int
    {
        return match (strtoupper(substr($limit, -1))) {
            'G' => (int) $limit * 1024 * 1024 * 1024,
            'M' => (int) $limit * 1024 * 1024,
            'K' => (int) $limit * 1024,
            default => (int) $limit,
        };
    }
}

<?php

namespace App\Modules\Reporting\Services;

use Mpdf\Mpdf;

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
            'tempDir' => storage_path('app/mpdf'),
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

        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }
}

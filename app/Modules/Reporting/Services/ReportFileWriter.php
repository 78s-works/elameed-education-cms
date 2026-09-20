<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Enums\ExportFormat;
use App\Modules\Reporting\Exporters\TabularReport;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Turns a {@see TabularReport} into a file on disk, in whichever format was
 * asked for. Runs inside the queue worker, never in a request.
 *
 * Spreadsheets are written row by row to a temp file, so a 50k-row export costs
 * one row of memory rather than the whole result set. A PDF cannot stream — the
 * renderer needs the whole document to paginate it — so it is capped by
 * `reports.max_rows`, and a truncated PDF says so on its last line rather than
 * quietly ending early.
 */
class ReportFileWriter
{
    public function __construct(private readonly PdfRenderer $pdf) {}

    /**
     * @return array{contents: string, rows: int, truncated: bool}
     */
    public function write(TabularReport $report, ExportFormat $format, string $locale, string $academy): array
    {
        return $format->isSpreadsheet()
            ? $this->spreadsheet($report, $format)
            : $this->document($report, $locale, $academy);
    }

    /** @return array{contents: string, rows: int, truncated: bool} */
    private function spreadsheet(TabularReport $report, ExportFormat $format): array
    {
        $max = (int) config('reports.max_rows');
        $path = tempnam(sys_get_temp_dir(), 'report-').'.'.$format->extension();

        $writer = $format === ExportFormat::Xlsx ? new XlsxWriter : new CsvWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($report->headers()));

        $count = 0;
        $truncated = false;

        foreach ($report->rows() as $row) {
            if ($count >= $max) {
                $truncated = true;
                break;
            }
            $writer->addRow(Row::fromValues($row));
            $count++;
        }

        // Totals belong in the file, not only on the screen the export came from.
        foreach ($report->totals() as $label => $value) {
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues([$label, $value]));
        }

        $writer->close();

        $contents = (string) file_get_contents($path);
        @unlink($path);

        return ['contents' => $contents, 'rows' => $count, 'truncated' => $truncated];
    }

    /** @return array{contents: string, rows: int, truncated: bool} */
    private function document(TabularReport $report, string $locale, string $academy): array
    {
        $max = (int) config('reports.max_rows');
        $rows = [];
        $truncated = false;

        foreach ($report->rows() as $row) {
            if (count($rows) >= $max) {
                $truncated = true;
                break;
            }
            $rows[] = $row;
        }

        $rtl = $locale === 'ar';

        $contents = $this->pdf->render('reports.table', [
            'title' => $report->title(),
            'academy' => $academy,
            'dir' => $rtl ? 'rtl' : 'ltr',
            'generatedLabel' => $rtl ? 'وقت الإصدار' : 'Generated',
            'generatedAt' => now()->format('Y-m-d H:i'),
            'filterLines' => $report->filterLines(),
            'headers' => $report->headers(),
            'rows' => $rows,
            'totals' => $report->totals(),
            'truncatedNote' => $truncated
                ? ($rtl
                    ? "التقرير يعرض أول {$max} صفًا فقط — صدّره كملف Excel للحصول على البيانات كاملة."
                    : "Showing the first {$max} rows only — export as Excel for the full data.")
                : null,
        ], $locale, $report->orientation());

        return ['contents' => $contents, 'rows' => count($rows), 'truncated' => $truncated];
    }
}

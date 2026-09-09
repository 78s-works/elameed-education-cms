<?php

namespace App\Modules\Reporting\Enums;

/**
 * File formats an export can produce.
 *
 * CSV and XLSX are spreadsheets — rows a human sorts and re-totals. PDF is a
 * document: it is what gets printed, attached to a message, or handed to someone
 * who will not open a spreadsheet, so its layout matters and its Arabic has to
 * be shaped correctly (see {@see \App\Modules\Reporting\Services\PdfRenderer}).
 */
enum ExportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Pdf = 'pdf';

    public function extension(): string
    {
        return $this->value;
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Pdf => 'application/pdf',
        };
    }

    public function isSpreadsheet(): bool
    {
        return $this !== self::Pdf;
    }
}

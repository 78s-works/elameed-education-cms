<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Commerce\Enums\SalesMethod;
use App\Modules\Tenancy\Services\TenantContext;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the sales ledger — the SAME rows and columns as the table, for the
 * SAME filter — as CSV or XLSX. Streamed, not buffered: rows are pulled from
 * {@see SalesLedgerQuery::stream()} and written straight to the output, so a
 * whole year exports without holding the result set in memory.
 *
 * Amounts are written in POUNDS here (minor units / 100). This is the one place
 * that converts: a spreadsheet is read by a human, and the API keeps integers.
 */
class SalesLedgerExporter
{
    public const HEADERS = [
        'Date & time',
        'Student',
        'Phone',
        'Item type',
        'Item',
        'Original price (EGP)',
        'Discount (EGP)',
        'Paid (EGP)',
        'Refunded (EGP)',
        'Coupon',
        'Payment method',
        'Status',
        'Reference',
    ];

    public function __construct(private readonly SalesLedgerQuery $query) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  string  $format  csv | xlsx
     */
    public function download(array $filters, string $format, string $filename): StreamedResponse
    {
        // The body is produced AFTER the request has been handled, when the
        // request-scoped tenant is already gone — so the resolved tenant is
        // captured here and pinned again inside the callback, otherwise every
        // tenant-scoped query in the stream would fail closed.
        $tenant = app(TenantContext::class)->tenantOrFail();

        return new StreamedResponse(function () use ($filters, $format, $tenant): void {
            app(TenantContext::class)->setTenant($tenant);
            $rows = $this->query->stream($filters);

            $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(self::HEADERS));

            foreach ($rows as $row) {
                foreach ($this->lines($row) as $line) {
                    $writer->addRow(Row::fromValues($line));
                }
            }

            $writer->close();
        }, 200, [
            'Content-Type' => $format === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.'.$format.'"',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * One spreadsheet line per ITEM, the same sub-row split the table shows. The
     * transaction-level money (discount, refund) is attributed to the first line
     * so the column still sums to the transaction's real total.
     *
     * Public so the queued report exporters build their rows from the SAME
     * mapping as the inline download — a ledger PDF and a ledger XLSX that
     * disagreed about a discount would be worse than having no PDF.
     *
     * @param  array<string, mixed>  $row
     * @return list<list<string|float|int>>
     */
    public function lines(array $row): array
    {
        $items = $row['items'] === [] ? [null] : $row['items'];
        $lines = [];

        foreach ($items as $index => $item) {
            $first = $index === 0;
            $gross = $item === null ? (int) $row['gross_minor'] : (int) $item['price_minor'];
            // A transaction-level discount can't be split across items without
            // inventing an allocation, so it sits on the first line.
            $discount = $first ? (int) $row['discount_minor'] : 0;

            $lines[] = [
                (string) $row['occurred_at'],
                (string) ($row['student']['name'] ?? '—'),
                (string) ($row['student']['phone'] ?? ''),
                (string) ($item['item_type'] ?? ''),
                (string) ($item['title'] ?? '—'),
                $this->pounds($gross),
                $this->pounds($discount),
                $this->pounds($first ? (int) $row['net_minor'] : $gross),
                $this->pounds($first ? (int) $row['refunded_minor'] : 0),
                (string) ($row['coupon_code'] ?? ''),
                $this->methodLabel((string) $row['method']),
                (string) $row['status'],
                (string) $row['reference'],
            ];
        }

        return $lines;
    }

    private function pounds(int $minor): float
    {
        return round($minor / 100, 2);
    }

    private function methodLabel(string $method): string
    {
        return SalesMethod::tryFrom($method)?->label() ?? $method;
    }
}

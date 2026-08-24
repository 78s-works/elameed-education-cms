<?php

namespace App\Modules\Reporting\Http\Controllers\Teacher;

use App\Modules\Commerce\Http\Controllers\Teacher\RefundController;
use App\Modules\Reporting\Http\Requests\SalesLedgerRequest;
use App\Modules\Reporting\Services\SalesLedgerExporter;
use App\Modules\Reporting\Services\SalesLedgerQuery;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The teacher's sales ledger (M17): the transaction list behind the dashboard's
 * revenue widgets. Read-only; refunding lives in Commerce
 * ({@see RefundController}) behind
 * its own permission, because reading the books and moving money back are
 * different jobs.
 *
 * All four endpoints take the SAME filters, so the totals, the table, the top-up
 * panel and the export always describe the same slice.
 */
class SalesLedgerController
{
    public function __construct(
        private readonly SalesLedgerQuery $ledger,
        private readonly SalesLedgerExporter $exporter,
    ) {}

    /** Paginated rows, newest first, plus the totals for the active filter. */
    public function index(SalesLedgerRequest $request): JsonResponse
    {
        $result = $this->ledger->page(
            $request->filters(),
            $request->perPage(),
            $request->pageNumber(),
        );

        $rows = $result['rows'];

        return response()->json([
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'last_page' => $rows->lastPage(),
            ],
            'totals' => $result['totals'],
        ]);
    }

    /** Dropdown vocabularies: the academy's sellable items, methods, statuses. */
    public function filters(): JsonResponse
    {
        return response()->json(['data' => $this->ledger->filterOptions()]);
    }

    /**
     * Wallet top-ups for the same window — reported separately and deliberately
     * NOT part of the sales figure (see the note on the query service).
     */
    public function topups(SalesLedgerRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->ledger->topups($request->filters())]);
    }

    /** The current filter as a spreadsheet, same columns as the table. */
    public function export(SalesLedgerRequest $request): StreamedResponse
    {
        return $this->exporter->download(
            $request->filters(),
            (string) $request->input('format', 'csv'),
            'sales-'.now()->format('Y-m-d'),
        );
    }
}

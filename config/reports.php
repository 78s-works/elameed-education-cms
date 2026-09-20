<?php

return [

    /*
     * PRIVATE disk for generated report files. Never publicly served: a report
     * carries student names, phone numbers and revenue, so it is streamed only
     * through the access-controlled download endpoint, which re-checks the
     * tenant and the requester.
     */
    'disk' => env('REPORT_EXPORT_DISK', 'local'),

    /*
     * How long a generated file stays downloadable. A report is a snapshot of a
     * moment; keeping it forever means stale revenue figures circulating as if
     * they were current, and a growing pile of personal data on disk with no
     * reason to still be there. The purge command (reports:purge-exports) drops
     * the file and marks the row expired.
     */
    'retention_days' => (int) env('REPORT_EXPORT_RETENTION_DAYS', 7),

    /*
     * A cap on how many rows one export writes. The sales ledger streams, so it
     * is not memory-bound, but an unbounded PDF of 200k rows is not a document
     * anyone reads — it is a way to occupy the exports worker for an hour. Rows
     * beyond the cap are dropped and the file says so on its last line.
     */
    'max_rows' => (int) env('REPORT_EXPORT_MAX_ROWS', 50000),

];

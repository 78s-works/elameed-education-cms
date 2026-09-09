{{--
  One layout for every tabular report PDF: sales ledger, students, platform.
  Columns come from the exporter, so a report's PDF and its XLSX cannot drift
  apart — they are handed the same headers and the same rows.

  Kept deliberately plain: no external assets (mPDF would have to fetch them at
  render time, inside a queue worker), no colour that costs anything in print.
--}}
<style>
    body { font-family: dejavusans, sans-serif; font-size: 8pt; color: #1a1a1a; }
    h1 { font-size: 14pt; margin: 0 0 2mm; }
    .meta { font-size: 7.5pt; color: #666; margin-bottom: 4mm; }
    .meta span { margin-inline-end: 4mm; }
    table { width: 100%; border-collapse: collapse; }
    thead th {
        background: #0e7c66; color: #fff; padding: 1.6mm 1.2mm;
        font-size: 7.5pt; text-align: {{ $dir === 'rtl' ? 'right' : 'left' }};
    }
    tbody td { padding: 1.4mm 1.2mm; border-bottom: 0.2mm solid #e4e4e4; }
    tbody tr:nth-child(even) td { background: #f7f9f8; }
    .totals { margin-top: 4mm; font-size: 9pt; font-weight: bold; }
    .totals td { padding: 1.4mm 1.2mm; border-top: 0.4mm solid #0e7c66; }
    .truncated { margin-top: 3mm; font-size: 7.5pt; color: #a33; }
</style>

<h1>{{ $title }}</h1>
<div class="meta">
    <span>{{ $academy }}</span>
    <span>{{ $generatedLabel }}: {{ $generatedAt }}</span>
    @foreach ($filterLines as $line)
        <span>{{ $line }}</span>
    @endforeach
</div>

<table>
    <thead>
        <tr>@foreach ($headers as $header)<th>{{ $header }}</th>@endforeach</tr>
    </thead>
    <tbody>
        @foreach ($rows as $row)
            <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
        @endforeach
    </tbody>
</table>

@if (! empty($totals))
    <table class="totals">
        @foreach ($totals as $label => $value)
            <tr><td>{{ $label }}</td><td>{{ $value }}</td></tr>
        @endforeach
    </table>
@endif

@if ($truncatedNote)
    <p class="truncated">{{ $truncatedNote }}</p>
@endif

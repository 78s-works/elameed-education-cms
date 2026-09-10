<?php

namespace App\Modules\Reporting\Exporters;

use Generator;

/**
 * A report that is a table: a title, column headers, rows, and optional totals.
 *
 * Every format is derived from this one description, which is the point — the
 * XLSX and the PDF of a report cannot show different columns or different
 * totals, because neither knows how to produce a column on its own. Rows are a
 * Generator so a year of sales streams instead of being assembled in memory.
 *
 * Headers and labels take a locale rather than going through translation files:
 * the backend has no lang catalogue (its 59 `__()` calls fall through to their
 * English keys), and inventing one for four reports would be a bigger change
 * than the reports.
 */
abstract class TabularReport
{
    /** @param array<string, mixed> $filters */
    public function __construct(
        protected readonly array $filters = [],
        protected readonly string $locale = 'ar',
    ) {}

    /** Shown as the document heading and used in the file name. */
    abstract public function title(): string;

    /** @return list<string> */
    abstract public function headers(): array;

    /** @return Generator<int, list<string|int|float>> */
    abstract public function rows(): Generator;

    /**
     * Summary lines under the table — "Total paid: 12,340.00 EGP". Empty when a
     * report has nothing to sum.
     *
     * @return array<string, string>
     */
    public function totals(): array
    {
        return [];
    }

    /**
     * Human-readable echo of the filters this run used, so a file found later
     * still says what it covers.
     *
     * @return list<string>
     */
    public function filterLines(): array
    {
        return [];
    }

    /** 'P' or 'L' — wide tables need landscape or the columns crush. */
    public function orientation(): string
    {
        return 'P';
    }

    protected function isArabic(): bool
    {
        return $this->locale === 'ar';
    }

    /** Pick the locale's variant of a label pair. */
    protected function t(string $ar, string $en): string
    {
        return $this->isArabic() ? $ar : $en;
    }

    protected function pounds(int $minor): string
    {
        return number_format($minor / 100, 2);
    }
}

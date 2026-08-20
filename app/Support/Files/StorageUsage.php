<?php

namespace App\Support\Files;

use App\Support\Files\Models\Document;
use Illuminate\Support\Facades\DB;

/**
 * Storage totals for one tenant, shaped for the summary strip at the top of the
 * files tabs. Computed with three grouped aggregates rather than by loading rows,
 * so it stays cheap as a tenant's library grows.
 */
final class StorageUsage
{
    public function __construct(
        public readonly int $totalBytes,
        public readonly int $count,
        /** @var array<string, array{count: int, bytes: int}> */
        public readonly array $byKind,
        /** @var array<string, array{count: int, bytes: int}> */
        public readonly array $byPurpose,
    ) {}

    public static function forTenant(?int $tenantId, ?int $ownerId = null): self
    {
        $base = fn () => Document::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($ownerId !== null, fn ($q) => $q->where('owner_id', $ownerId));

        $totals = $base()
            ->selectRaw('COUNT(*) as row_count, COALESCE(SUM(size_bytes), 0) as total_bytes')
            ->first();

        return new self(
            totalBytes: (int) ($totals->total_bytes ?? 0),
            count: (int) ($totals->row_count ?? 0),
            byKind: self::group($base(), 'kind'),
            byPurpose: self::group($base(), 'purpose'),
        );
    }

    public function toArray(): array
    {
        return [
            'total_bytes' => $this->totalBytes,
            'count' => $this->count,
            'by_kind' => $this->flatten($this->byKind, 'kind'),
            'by_purpose' => $this->flatten($this->byPurpose, 'purpose'),
        ];
    }

    /** @return array<string, array{count: int, bytes: int}> */
    private static function group($query, string $column): array
    {
        return $query
            ->select($column)
            ->selectRaw('COUNT(*) as row_count, COALESCE(SUM(size_bytes), 0) as total_bytes')
            ->groupBy($column)
            ->get()
            ->mapWithKeys(fn ($row) => [
                (string) $row->{$column} => [
                    'count' => (int) $row->row_count,
                    'bytes' => (int) $row->total_bytes,
                ],
            ])
            ->all();
    }

    /** The API returns a list, not a map — stable ordering for the UI's bars. */
    private function flatten(array $grouped, string $key): array
    {
        $rows = [];

        foreach ($grouped as $value => $totals) {
            $rows[] = [$key => $value, 'count' => $totals['count'], 'bytes' => $totals['bytes']];
        }

        usort($rows, fn (array $a, array $b) => $b['bytes'] <=> $a['bytes']);

        return $rows;
    }
}

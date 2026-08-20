<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Models\TenantUser;
use DateTimeInterface;
use Illuminate\Support\Facades\Validator;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Bulk student-history import (M17). Reads an `.xlsx`/`.csv` upload and updates
 * each MATCHED student's per-academy profile/history fields. Rows are matched by
 * `phone` or `email` against existing users who are students of THIS tenant.
 *
 * Recognised columns (case-insensitive header): one match key (`phone`/`email`)
 * plus the canonical StudentProfile fields (StudentProfile::FIELDS). Unknown
 * columns are ignored. Every row is validated with StudentProfile::rules(), and a
 * per-row `applied` | `duplicate` | `failed` result is returned (mirrors the
 * Centers offline-sync contract).
 */
class StudentImportService
{
    /** @var array<int, int|null> tenant_id => first academic-year id */
    private array $defaultYearIds = [];

    /** Recognised header columns: the two match keys + the canonical profile fields. */
    private function knownColumns(): array
    {
        return array_merge(['phone', 'email'], StudentProfile::FIELDS);
    }

    /**
     * @return array<int, array<string, mixed>> per-row results
     */
    public function import(int $tenantId, string $path, string $extension): array
    {
        $reader = $this->readerFor($extension);
        $reader->open($path);

        $results = [];
        $seen = [];       // user ids already applied in THIS batch
        $rowNumber = 0;
        $header = null;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rowNumber++;
                    $cells = array_map([$this, 'stringify'], $row->toArray());

                    if ($header === null) {
                        $header = $this->mapHeader($cells);

                        continue;
                    }

                    if ($this->isBlank($cells)) {
                        continue;
                    }

                    $results[] = $this->applyRow($tenantId, $rowNumber, $this->associate($header, $cells), $seen);
                }

                break; // first sheet only
            }
        } finally {
            $reader->close();
        }

        return $results;
    }

    private function readerFor(string $extension): ReaderInterface
    {
        return match (strtolower($extension)) {
            'csv', 'txt' => new CsvReader(),
            'xlsx' => new XlsxReader(),
            default => throw new \InvalidArgumentException("Unsupported import type: {$extension}"),
        };
    }

    /**
     * @param  array<int, string>  $cells
     * @return array<int, string> column index => canonical key
     */
    private function mapHeader(array $cells): array
    {
        $known = $this->knownColumns();
        $map = [];
        foreach ($cells as $i => $label) {
            $key = strtolower(trim($label));
            if (in_array($key, $known, true)) {
                $map[$i] = $key;
            }
        }

        return $map;
    }

    /**
     * @param  array<int, string>  $header  index => canonical key
     * @param  array<int, string>  $cells
     * @return array<string, string>
     */
    private function associate(array $header, array $cells): array
    {
        $assoc = [];
        foreach ($header as $i => $key) {
            $value = trim((string) ($cells[$i] ?? ''));
            if ($value !== '') {
                $assoc[$key] = $value;
            }
        }

        return $assoc;
    }

    /**
     * @param  array<string, string>  $data
     * @param  array<int, bool>  $seen  user_id => true (already applied this batch)
     * @return array<string, mixed>
     */
    private function applyRow(int $tenantId, int $rowNumber, array $data, array &$seen): array
    {
        try {
            $student = $this->resolveStudent($tenantId, $data);
            if ($student === null) {
                return $this->fail($rowNumber, 'No matching student for the given phone/email.');
            }

            if (isset($seen[$student->id])) {
                return $this->result($rowNumber, 'duplicate', ['student_uuid' => $student->uuid]);
            }

            $fields = StudentProfile::fields($data);
            if ($fields === []) {
                return $this->fail($rowNumber, 'Row has no history fields to update.');
            }

            $validator = Validator::make($fields, StudentProfile::rules());
            if ($validator->fails()) {
                return $this->fail($rowNumber, (string) $validator->errors()->flatten()->first());
            }

            // A student normally already has a profile (registration creates it).
            // If they don't, one is created here — and it MUST carry an academic
            // year: `student_profiles.academic_year_id` is NOT NULL, because an
            // unpinned student is refused the panel outright. Fall back to the
            // tenant's first year, same rule the legacy backfill migration used.
            StudentProfile::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenantId, 'user_id' => $student->id],
                $fields + ['academic_year_id' => $this->defaultYearId($tenantId)],
            );

            $seen[$student->id] = true;

            return $this->result($rowNumber, 'applied', ['student_uuid' => $student->uuid]);
        } catch (Throwable) {
            return $this->fail($rowNumber, 'Could not process this row.');
        }
    }

    /** The tenant's first academic year — the pin a newly created profile gets
     *  when the import row does not identify one. Cached per tenant per run. */
    private function defaultYearId(int $tenantId): ?int
    {
        return $this->defaultYearIds[$tenantId] ??= AcademicYear::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('id');
    }

    /** Match a student user (of THIS tenant) by phone or email. */
    private function resolveStudent(int $tenantId, array $data): ?User
    {
        $user = null;
        if (! empty($data['phone'])) {
            $user = User::query()->where('phone', $data['phone'])->first();
        }
        if ($user === null && ! empty($data['email'])) {
            $user = User::query()->where('email', $data['email'])->first();
        }
        if ($user === null) {
            return null;
        }

        $isStudent = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('role', TenantUserRole::Student->value)
            ->exists();

        return $isStudent ? $user : null;
    }

    /** @param mixed $value */
    private function stringify($value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_float($value) && floor($value) === $value) {
            return (string) (int) $value; // avoid "1.0E+10" for numeric phones
        }

        return trim((string) $value);
    }

    /** @param array<int, string> $cells */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $c) {
            if (trim((string) $c) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function result(int $rowNumber, string $status, array $extra = []): array
    {
        return ['row' => $rowNumber, 'status' => $status] + $extra;
    }

    /** @return array<string, mixed> */
    private function fail(int $rowNumber, string $message): array
    {
        return ['row' => $rowNumber, 'status' => 'failed', 'message' => $message];
    }
}

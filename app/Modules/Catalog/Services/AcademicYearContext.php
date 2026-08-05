<?php

namespace App\Modules\Catalog\Services;

/**
 * Request-scoped holder of the resolved academic year (set by ResolveAcademicYear).
 * Mirrors TenantContext: the BelongsToAcademicYear trait and any content code read
 * the same instance to answer "which academic year is this request scoped to?".
 */
class AcademicYearContext
{
    private ?int $id = null;

    public function set(int $id): void
    {
        $this->id = $id;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function hasYear(): bool
    {
        return $this->id !== null;
    }

    public function forget(): void
    {
        $this->id = null;
    }
}

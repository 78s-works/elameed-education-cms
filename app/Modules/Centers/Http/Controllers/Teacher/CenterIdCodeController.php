<?php

namespace App\Modules\Centers\Http\Controllers\Teacher;

use App\Modules\Centers\Enums\CodeStatus;
use App\Modules\Centers\Http\Requests\GenerateCenterIdCodesRequest;
use App\Modules\Centers\Http\Resources\CenterIdCodeResource;
use App\Modules\Centers\Models\Center;
use App\Modules\Centers\Models\CenterIdCode;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * /teacher/center-id-codes (B20) — mint & list Center ID-codes. Sequential,
 * grade-encoded, per-center. Gated role:teacher,assistant + permission:centers,
 * year-scoped by the X-Academic-Year middleware: index only lists the active
 * year's codes (BelongsToAcademicYear scope) and batch stamps that year onto
 * every minted row. Sibling of, not merged with, /teacher/codes (M12 recharge
 * codes): different table, different lifecycle. Mirrors that batch-generator shape.
 */
class CenterIdCodeController
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * List codes, newest first. Filters: ?filter[center]=<uuid>, filter[grade]=1|2|3,
     * filter[batch_id]=<uuid>, filter[status]=used|unused (or raw active|redeemed|disabled).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $this->normaliseStatus($request->input('filter.status'));

        $codes = CenterIdCode::query()
            ->with(['center:id,uuid', 'academicYear:id,uuid'])
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('filter.grade'), fn ($q, $g) => $q->where('grade', (int) $g))
            ->when($request->input('filter.batch_id'), fn ($q, $b) => $q->where('batch_id', $b))
            ->when($request->input('filter.center'), fn ($q, $uuid) => $q->whereHas(
                'center',
                fn ($c) => $c->where('uuid', $uuid),
            ))
            ->latest('id')
            ->paginate(50);

        return CenterIdCodeResource::collection($codes);
    }

    /**
     * Mint `count` sequential grade-encoded codes for one center. The running
     * sequence continues from the highest existing (center, grade) pair; the
     * lockForUpdate + transaction keep two concurrent batches from colliding.
     */
    public function batch(GenerateCenterIdCodesRequest $request): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $data = $request->validated();
        $grade = (int) $data['grade'];
        $count = (int) $data['count'];
        $userId = $request->user()->getKey();
        $centerId = (int) Center::query()->where('uuid', $data['center'])->value('id');

        $codes = DB::transaction(function () use ($tenantId, $centerId, $grade, $count, $userId): array {
            $batchId = (string) Str::uuid();
            $start = (int) CenterIdCode::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('center_id', $centerId)
                ->where('grade', $grade)
                ->lockForUpdate()
                ->max('sequence');

            $created = [];
            for ($i = 1; $i <= $count; $i++) {
                $sequence = $start + $i;
                $created[] = CenterIdCode::create([
                    'tenant_id' => $tenantId,
                    'center_id' => $centerId,
                    'grade' => $grade,
                    'sequence' => $sequence,
                    'code' => $this->encode($grade, $centerId, $sequence),
                    'status' => CodeStatus::Active->value,
                    'batch_id' => $batchId,
                    'generated_by' => $userId,
                ]);
            }

            return $created;
        });

        return CenterIdCodeResource::collection($codes)->response()->setStatusCode(201);
    }

    /** Grade-encoded, per-center-unique: leading digit is the grade (1|2|3). */
    private function encode(int $grade, int $centerId, int $sequence): string
    {
        return sprintf('%d-%d-%06d', $grade, $centerId, $sequence);
    }

    /** Map the used/unused shorthand onto the shared CodeStatus vocabulary. */
    private function normaliseStatus(?string $status): ?string
    {
        return match ($status) {
            null, '' => null,
            'unused' => CodeStatus::Active->value,
            'used' => CodeStatus::Redeemed->value,
            default => $status,
        };
    }
}

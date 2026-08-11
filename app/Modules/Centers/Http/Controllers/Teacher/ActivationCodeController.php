<?php

namespace App\Modules\Centers\Http\Controllers\Teacher;

use App\Modules\Centers\Enums\CodeStatus;
use App\Modules\Centers\Http\Requests\GenerateCodesRequest;
use App\Modules\Centers\Http\Resources\ActivationCodeResource;
use App\Modules\Centers\Models\ActivationCode;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * /teacher/codes (M12) — generate/list/disable activation (recharge) codes.
 */
class ActivationCodeController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $codes = ActivationCode::query()
            ->when($request->input('filter.status'), fn ($q, $s) => $this->applyStatusFilter($q, $s))
            ->when($request->input('filter.type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->input('filter.batch'), fn ($q, $b) => $q->where('batch', $b))
            ->latest('id')
            ->paginate(50);

        return ActivationCodeResource::collection($codes);
    }

    public function batch(GenerateCodesRequest $request): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $data = $request->validated();
        $count = (int) $data['count'];
        $generatedBy = $request->user()?->getKey();

        $codes = DB::transaction(function () use ($tenantId, $data, $count, $generatedBy): array {
            $created = [];
            for ($i = 0; $i < $count; $i++) {
                $created[] = ActivationCode::create([
                    'tenant_id' => $tenantId,
                    'code' => $this->uniqueCode($tenantId),
                    'type' => $data['type'],
                    'amount_minor' => $data['type'] === 'wallet' ? (int) $data['amount_minor'] : null,
                    'course_id' => $data['type'] === 'course' ? (int) $data['course_id'] : null,
                    'center_id' => $data['center_id'] ?? null,
                    'generated_by' => $generatedBy,
                    'batch' => $data['batch'] ?? null,
                    'status' => CodeStatus::Active->value,
                    'expires_at' => $data['expires_at'] ?? null,
                ]);
            }

            return $created;
        });

        return ActivationCodeResource::collection($codes)->response()->setStatusCode(201);
    }

    public function disable(ActivationCode $code): ActivationCodeResource
    {
        if ($code->status === CodeStatus::Active) {
            $code->update(['status' => CodeStatus::Disabled->value]);
        }

        return new ActivationCodeResource($code);
    }

    /**
     * Narrow the list to a lifecycle bucket. `unused`/`used`/`expired` are the B22
     * printable-scratch-code views ("expired" = still active but past its expiry, a
     * derived state, not a stored status); anything else falls through as a raw status.
     */
    private function applyStatusFilter(Builder $query, string $status): Builder
    {
        return match ($status) {
            'expired' => $query->where('status', CodeStatus::Active->value)
                ->whereNotNull('expires_at')->where('expires_at', '<', now()),
            'unused' => $query->where('status', CodeStatus::Active->value)
                ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now())),
            'used' => $query->where('status', CodeStatus::Redeemed->value),
            default => $query->where('status', $status),
        };
    }

    /**
     * Printable scratch-code (B22): 12 chars grouped `XXXX-XXXX-XXXX` from an
     * unambiguous alphabet (no 0/O/1/I) so a student can read one off a card and type
     * it. Uniqueness is per-tenant, guarded by the DB unique index as a backstop.
     */
    private function uniqueCode(int $tenantId): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $raw = '';
            for ($i = 0; $i < 12; $i++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = implode('-', str_split($raw, 4));
        } while (ActivationCode::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('code', $code)->exists());

        return $code;
    }
}

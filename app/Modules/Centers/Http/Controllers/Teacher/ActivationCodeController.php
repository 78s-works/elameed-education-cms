<?php

namespace App\Modules\Centers\Http\Controllers\Teacher;

use App\Modules\Centers\Enums\CodeStatus;
use App\Modules\Centers\Http\Requests\GenerateCodesRequest;
use App\Modules\Centers\Http\Resources\ActivationCodeResource;
use App\Modules\Centers\Models\ActivationCode;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * /teacher/codes (M12) — generate/list/disable activation (recharge) codes.
 */
class ActivationCodeController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $codes = ActivationCode::query()
            ->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))
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

        $codes = DB::transaction(function () use ($tenantId, $data, $count): array {
            $created = [];
            for ($i = 0; $i < $count; $i++) {
                $created[] = ActivationCode::create([
                    'tenant_id' => $tenantId,
                    'code' => $this->uniqueCode($tenantId),
                    'type' => $data['type'],
                    'amount_minor' => $data['type'] === 'wallet' ? (int) $data['amount_minor'] : null,
                    'course_id' => $data['type'] === 'course' ? (int) $data['course_id'] : null,
                    'center_id' => $data['center_id'] ?? null,
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

    private function uniqueCode(int $tenantId): string
    {
        do {
            $code = strtoupper(Str::random(12));
        } while (ActivationCode::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('code', $code)->exists());

        return $code;
    }
}

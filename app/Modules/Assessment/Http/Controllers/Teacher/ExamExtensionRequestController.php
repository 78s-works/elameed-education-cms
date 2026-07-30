<?php

namespace App\Modules\Assessment\Http\Controllers\Teacher;

use App\Modules\Assessment\Models\ExamTimeExtension;
use App\Modules\Assessment\Services\ExamTimeExtensionService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /teacher/exam-extension-requests (doc 11 R6) — staff review of student
 * exam/quiz time-extension requests. Bound rows are tenant-scoped, so
 * cross-tenant ids 404. Granting adds the minutes to the student's exam timer.
 */
class ExamExtensionRequestController
{
    public function __construct(
        private readonly ExamTimeExtensionService $service,
        private readonly TenantContext $context,
    ) {}

    public function index(): JsonResponse
    {
        $rows = ExamTimeExtension::query()
            ->pending()
            ->with(['exam:id,uuid,title', 'user:id,uuid,name,phone'])
            ->orderByDesc('requested_at')
            ->get()
            ->map(fn (ExamTimeExtension $r) => [
                'id' => $r->id,
                'exam' => ['uuid' => $r->exam?->uuid, 'title' => $r->exam?->title],
                'student' => ['uuid' => $r->user?->uuid, 'name' => $r->user?->name, 'phone' => $r->user?->phone],
                'requested_minutes' => $r->requested_minutes,
                'status' => $r->status->value,
                'requested_at' => $r->requested_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function grant(Request $request, ExamTimeExtension $examExtension): JsonResponse
    {
        $minutes = $request->integer('minutes') ?: null;

        return $this->decide($request, $examExtension, true, $minutes);
    }

    public function deny(Request $request, ExamTimeExtension $examExtension): JsonResponse
    {
        return $this->decide($request, $examExtension, false, null);
    }

    private function decide(Request $request, ExamTimeExtension $row, bool $grant, ?int $minutes): JsonResponse
    {
        $tenantId = (int) $this->context->tenantOrFail()->getKey();
        $decided = $this->service->decide($tenantId, $row, $grant, $minutes, (int) $request->user()->getKey());

        return response()->json(['data' => [
            'id' => $decided->id,
            'status' => $decided->status->value,
            'granted_minutes' => $decided->granted_minutes,
        ]]);
    }
}

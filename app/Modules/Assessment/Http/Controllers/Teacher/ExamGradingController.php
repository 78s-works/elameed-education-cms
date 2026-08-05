<?php

namespace App\Modules\Assessment\Http\Controllers\Teacher;

use App\Modules\Assessment\Http\Requests\GradeAttemptRequest;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Assessment\Services\GradingService;
use App\Modules\Engagement\Services\PointsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Teacher grading of exam submissions (M08). Auto-graded objective questions are
 * already scored; this assigns points to the subjective ones and finalises.
 */
class ExamGradingController
{
    public function __construct(
        private readonly GradingService $grading,
        private readonly PointsService $points,
    ) {}

    public function submissions(Request $request, Exam $exam): JsonResponse
    {
        $attempts = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->when($request->boolean('filter.needs_grading'), fn ($q) => $q->where('needs_manual_grade', true))
            ->whereIn('status', ['submitted', 'graded'])
            ->with('user:id,uuid,name,phone')
            ->latest('submitted_at')
            ->get()
            ->map(fn (ExamAttempt $a) => [
                'attempt_id' => $a->id,
                'student' => ['uuid' => $a->user?->uuid, 'name' => $a->user?->name, 'phone' => $a->user?->phone],
                'status' => $a->status->value,
                'score' => $a->score,
                'max_score' => $a->max_score,
                'needs_manual_grade' => $a->needs_manual_grade,
                'submitted_at' => $a->submitted_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $attempts]);
    }

    public function grade(GradeAttemptRequest $request, Exam $exam, ExamAttempt $attempt): JsonResponse
    {
        abort_unless($attempt->exam_id === $exam->id, 404);

        $correctedFile = $this->storeCorrectedFile($request, $exam, $attempt);

        $attempt = $this->grading->applyManualGrades(
            $attempt,
            $request->validated('grades'),
            $request->validated('feedback'),
            $correctedFile,
        );

        // Award points once the attempt is fully graded and passing.
        if ($attempt->status->value === 'graded'
            && $exam->passed((int) $attempt->score, (int) $attempt->max_score) === true) {
            $this->points->award((int) $exam->tenant_id, (int) $attempt->user_id,
                (int) config('gamification.exam_points', 20), 'exam.passed', 'exam', $exam->id);
        }

        return response()->json(['data' => [
            'attempt_id' => $attempt->id,
            'status' => $attempt->status->value,
            'score' => $attempt->score,
            'max_score' => $attempt->max_score,
            'needs_manual_grade' => $attempt->needs_manual_grade,
            'feedback' => $attempt->feedback,
            'corrected_file' => $this->correctedFileInfo($attempt),
        ]]);
    }

    /**
     * Store an optional teacher-attached corrected/annotated file on the private
     * assignments disk, replacing any previous one. Returns the pointer to persist.
     *
     * @return array{path: string, name: string, size: int, mime: string}|null
     */
    private function storeCorrectedFile(GradeAttemptRequest $request, Exam $exam, ExamAttempt $attempt): ?array
    {
        $file = $request->file('corrected_file');
        if ($file === null) {
            return null;
        }

        $disk = (string) config('assessment.upload_disk', 'local');

        $previous = $attempt->corrected_file['path'] ?? null;
        if ($previous !== null) {
            Storage::disk($disk)->delete($previous);
        }

        $path = $file->store("assignments/{$exam->tenant_id}/{$attempt->id}/corrected", $disk);

        return [
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime' => $file->getClientMimeType(),
        ];
    }

    /** Public (name/size) view of the corrected file pointer, without the path. */
    private function correctedFileInfo(ExamAttempt $attempt): ?array
    {
        $file = $attempt->corrected_file;
        if ($file === null || empty($file['path'])) {
            return null;
        }

        return ['name' => $file['name'] ?? null, 'size' => $file['size'] ?? null];
    }

    /** Download the file a student submitted for a `file`-type question. */
    public function downloadFile(Request $request, Exam $exam, ExamAttempt $attempt, int $question): StreamedResponse
    {
        abort_unless($attempt->exam_id === $exam->id, 404);

        $file = $attempt->answers[$question]['file'] ?? null;
        abort_if($file === null || empty($file['path']), 404, 'No file submitted for this question.');

        $disk = (string) config('assessment.upload_disk', 'local');
        $path = (string) $file['path'];

        // Submissions live only under assignments/; reject anything else.
        abort_unless(str_starts_with($path, 'assignments/') && ! str_contains($path, '..'), 404);
        abort_unless(Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->download($path, $file['name'] ?? basename($path));
    }
}

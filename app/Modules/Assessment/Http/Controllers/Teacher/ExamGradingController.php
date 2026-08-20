<?php

namespace App\Modules\Assessment\Http\Controllers\Teacher;

use App\Modules\Assessment\Http\Requests\GradeAttemptRequest;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamAttempt;
use App\Modules\Assessment\Services\GradingService;
use App\Modules\Engagement\Services\PointsService;
use App\Support\Files\DocumentService;
use App\Support\Files\Enums\DocumentPurpose;
use App\Support\Files\Models\Document;
use App\Support\Files\StoreOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        private readonly DocumentService $documents,
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

        $this->storeCorrectedFile($request, $attempt);

        $attempt = $this->grading->applyManualGrades(
            $attempt,
            $request->validated('grades'),
            $request->validated('feedback'),
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
     * Attach the teacher's corrected/annotated return to the attempt, replacing
     * any previous one. Storing it as a document means the old file is actually
     * deleted rather than left behind, and the student's download goes through
     * the same policy as every other private file.
     */
    private function storeCorrectedFile(GradeAttemptRequest $request, ExamAttempt $attempt): ?Document
    {
        $file = $request->file('corrected_file');

        if ($file === null) {
            return null;
        }

        $previous = $attempt->firstDocumentFor(DocumentPurpose::AssignmentCorrected);

        if ($previous !== null) {
            $this->documents->delete($previous);
        }

        return $this->documents->store(
            $file,
            DocumentPurpose::AssignmentCorrected,
            new StoreOptions(owner: $attempt),
        );
    }

    /** Public (name/size) view of the corrected file, without the storage key. */
    private function correctedFileInfo(ExamAttempt $attempt): ?array
    {
        $document = $attempt->firstDocumentFor(DocumentPurpose::AssignmentCorrected);

        return $document === null ? null : [
            'uuid' => $document->uuid,
            'name' => $document->original_name,
            'size' => $document->size_bytes,
        ];
    }

    /** Download the file a student submitted for a `file`-type question. */
    public function downloadFile(Request $request, Exam $exam, ExamAttempt $attempt, int $question): StreamedResponse
    {
        abort_unless($attempt->exam_id === $exam->id, 404);

        $uuid = $attempt->answers[$question]['document_uuid'] ?? null;
        abort_if($uuid === null, 404, 'No file submitted for this question.');

        $document = $attempt->documents()
            ->ofPurpose(DocumentPurpose::AssignmentSubmission)
            ->where('uuid', $uuid)
            ->first();

        abort_if($document === null, 404, 'No file submitted for this question.');

        return $this->documents->download($document);
    }
}

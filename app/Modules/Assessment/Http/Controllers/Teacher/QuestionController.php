<?php

namespace App\Modules\Assessment\Http\Controllers\Teacher;

use App\Modules\Assessment\Http\Requests\QuestionRequest;
use App\Modules\Assessment\Http\Resources\QuestionResource;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Questions attached to an exam (M08). Author view returns the `correct` key.
 */
class QuestionController
{
    public function index(Exam $exam): AnonymousResourceCollection
    {
        return QuestionResource::collection(
            $exam->questions()->orderBy('sort_order')->orderBy('id')->get()
        );
    }

    public function store(QuestionRequest $request, Exam $exam): JsonResponse
    {
        $question = $exam->questions()->create($request->validated());

        return (new QuestionResource($question))->response()->setStatusCode(201);
    }

    public function update(QuestionRequest $request, Exam $exam, Question $question): QuestionResource
    {
        $this->assertOwnership($exam, $question);
        $question->update($request->validated());

        return new QuestionResource($question);
    }

    public function destroy(Exam $exam, Question $question): Response
    {
        $this->assertOwnership($exam, $question);
        $question->delete();

        return response()->noContent();
    }

    private function assertOwnership(Exam $exam, Question $question): void
    {
        abort_unless($question->exam_id === $exam->id, 404);
    }
}

<?php

namespace App\Modules\Assessment\Http\Resources;

use App\Modules\Assessment\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Exam
 */
class ExamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'course_id' => $this->course_id,
            'lesson_id' => $this->lesson_id,
            'title' => $this->title,
            'type' => $this->type->value,
            'mode' => $this->mode->value,
            'pass_percent' => $this->pass_percent,
            'duration_min' => $this->duration_min,
            'attempts_allowed' => $this->attempts_allowed,
            'question_order' => $this->question_order,
            'scoring' => $this->scoring,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'result_visibility' => $this->result_visibility,
            'show_answers' => $this->show_answers,
            'depends_on_exam_id' => $this->depends_on_exam_id,
            'is_published' => $this->is_published,
            'questions_count' => $this->whenCounted('questions'),
        ];
    }
}

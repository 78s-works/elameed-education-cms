<?php

namespace App\Modules\Assessment\Http\Resources;

use App\Modules\Assessment\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Author view — includes the `correct` answer key. Only ever returned to the
 * teacher. Students get PublicQuestionResource (no answer key).
 *
 * @mixin Question
 */
class QuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'body' => $this->body,
            'options' => $this->options,
            'correct' => $this->correct, // teacher-only
            'points' => $this->points,
            'book_ref' => $this->book_ref,
            'sort_order' => $this->sort_order,
        ];
    }
}

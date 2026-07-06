<?php

namespace App\Modules\Assessment\Http\Resources;

use App\Modules\Assessment\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Student view during an attempt — the answer key (`correct`) is deliberately
 * omitted.
 *
 * @mixin Question
 */
class PublicQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'body' => $this->body,
            'options' => $this->options,
            'points' => $this->points,
            'book_ref' => $this->book_ref,
        ];
    }
}

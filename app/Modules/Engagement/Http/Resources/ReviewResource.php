<?php

namespace App\Modules\Engagement\Http\Resources;

use App\Modules\Engagement\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The review shape the landing + course pages consume
 * (LANDING_CONTRACT_V2.md).
 *
 * @mixin Review
 */
class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Linked student's name, or the teacher-authored testimonial's author name.
            'student_name' => $this->displayName(),
            'course_title' => $this->course?->title,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'is_visible' => (bool) $this->is_visible,
            'is_teacher_authored' => $this->user_id === null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Modules\Assessment\Models;

use App\Modules\Assessment\Enums\QuestionType;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question — attached to an exam (`exam_id` set) or a reusable bank item
 * (`exam_id` null). `correct` is never exposed to students.
 *
 * @property QuestionType $type
 */
class Question extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'exam_id', 'category_id', 'type', 'body', 'options', 'correct', 'points', 'book_ref', 'sort_order',
    ];

    protected $casts = [
        'type' => QuestionType::class,
        'options' => 'array',
        'correct' => 'array',
        'book_ref' => 'array',
        'points' => 'integer',
    ];

    protected $hidden = [
        'correct', // never leak the answer key in API responses
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }
}

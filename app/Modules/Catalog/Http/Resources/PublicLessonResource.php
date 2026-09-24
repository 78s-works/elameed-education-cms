<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * The anonymous-safe projection of a lesson for the public catalogue
 * (GET /catalogue?view=lessons). Same shape as {@see LessonResource} minus the
 * video link: the teacher's authoring screens need `youtube_url`, but on a
 * no-auth route it hands every paid YouTube lesson out for free. Students only
 * ever reach a video through the playback endpoint, after its access checks.
 *
 * @mixin Lesson
 */
class PublicLessonResource extends LessonResource
{
    /** Fields that must never leave the server on a public route. */
    private const HIDDEN = ['youtube_url'];

    public function toArray(Request $request): array
    {
        return Arr::except(parent::toArray($request), self::HIDDEN);
    }
}

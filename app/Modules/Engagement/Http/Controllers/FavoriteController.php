<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Catalog\Models\Course;
use App\Modules\Engagement\Models\Favorite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Student course favorites (M20). Tenant-scoped to the current academy.
 */
class FavoriteController
{
    public function index(Request $request): JsonResponse
    {
        $items = Favorite::query()
            ->where('user_id', $request->user()->getKey())
            ->with('course:id,uuid,title,slug,cover_url')
            ->latest('id')
            ->get()
            ->map(fn (Favorite $f) => [
                'uuid' => $f->course?->uuid,
                'title' => $f->course?->title,
                'slug' => $f->course?->slug,
                'cover_url' => $f->course?->cover_url,
            ]);

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $uuid = $request->input('course');
        $course = Course::query()->where('uuid', $uuid)->first();

        if ($course === null) {
            throw ValidationException::withMessages(['course' => 'Course not found.']);
        }

        Favorite::query()->firstOrCreate([
            'user_id' => $request->user()->getKey(),
            'course_id' => $course->id,
        ]);

        return response()->json(['data' => ['favorited' => true]], 201);
    }

    public function destroy(Request $request, Course $course): JsonResponse
    {
        Favorite::query()
            ->where('user_id', $request->user()->getKey())
            ->where('course_id', $course->id)
            ->delete();

        return response()->json(['data' => ['favorited' => false]]);
    }
}

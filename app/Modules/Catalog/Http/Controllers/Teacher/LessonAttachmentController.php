<?php

namespace App\Modules\Catalog\Http\Controllers\Teacher;

use App\Modules\Catalog\Http\Requests\AttachmentRequest;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Http\Resources\MediaAssetResource;
use App\Modules\Media\Models\MediaAsset;
use App\Support\Files\DocumentService;
use App\Support\Files\Enums\DocumentPurpose;
use App\Support\Files\Http\Resources\DocumentResource;
use App\Support\Files\Models\Document;
use App\Support\Files\StoreOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * /teacher/lessons/{lesson}/attachments (FR-M04-01) — the lesson's materials.
 *
 * Two different things share this endpoint, and they are stored differently:
 *
 *   • an uploaded PDF or file → a `documents` row attached to the lesson. These
 *     used to be forced into `media_assets`, a table built for the HLS pipeline,
 *     where every column about encryption, renditions and duration sat null.
 *   • an external link → still a `media_assets` row of type `link`. A URL is not
 *     a stored file, so it has no business in the documents ledger, exactly as
 *     with `youtube_url`.
 *
 * Uploads are private: a lesson PDF is now only readable by someone the lesson's
 * own enrollment check lets in, which was not true when these went to the public
 * disk under a guessable name.
 */
class LessonAttachmentController
{
    public function __construct(private readonly DocumentService $documents) {}

    public function index(Lesson $lesson): JsonResponse
    {
        return response()->json([
            'data' => [
                'files' => DocumentResource::collection(
                    $lesson->documentsFor(DocumentPurpose::LessonAttachment)
                )->resolve(),
                'links' => MediaAssetResource::collection(
                    $lesson->links()->orderBy('sort_order')->get()
                )->resolve(),
            ],
        ]);
    }

    public function store(AttachmentRequest $request, Lesson $lesson): JsonResponse
    {
        $data = $request->validated();

        if ($data['type'] === 'link') {
            $asset = MediaAsset::create([
                'lesson_id' => $lesson->getKey(),
                'type' => MediaType::Link->value,
                'status' => MediaStatus::Ready->value,
                'title' => $data['title'] ?? null,
                'url' => $data['url'],
                'downloadable' => $data['downloadable'] ?? false,
            ]);

            return (new MediaAssetResource($asset))->response()->setStatusCode(201);
        }

        $document = $this->documents->store(
            $request->file('file'),
            DocumentPurpose::LessonAttachment,
            new StoreOptions(
                owner: $lesson,
                meta: array_filter(['title' => $data['title'] ?? null]),
            ),
        );

        return (new DocumentResource($document))->response()->setStatusCode(201);
    }

    /** Remove an uploaded material. The blob goes with the row — permanently. */
    public function destroy(Lesson $lesson, Document $document): Response
    {
        abort_unless(
            $document->documentable_type === $lesson->getMorphClass()
                && (int) $document->documentable_id === (int) $lesson->getKey(),
            404,
        );

        $this->documents->delete($document);

        return response()->noContent();
    }

    /** Remove an external link (no file involved). */
    public function destroyLink(Lesson $lesson, MediaAsset $link): Response
    {
        abort_unless($link->lesson_id === $lesson->getKey(), 404);
        abort_if($link->type !== MediaType::Link, 404);

        $link->delete();

        return response()->noContent();
    }
}

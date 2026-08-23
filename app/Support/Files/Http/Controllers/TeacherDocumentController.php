<?php

namespace App\Support\Files\Http\Controllers;

use App\Support\Files\DocumentLinkResolver;
use App\Support\Files\DocumentService;
use App\Support\Files\Enums\DocumentKind;
use App\Support\Files\Enums\DocumentPurpose;
use App\Support\Files\Exceptions\DocumentException;
use App\Support\Files\Http\Resources\DocumentResource;
use App\Support\Files\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The academy's file library — every document in the tenant, in one place.
 *
 * This is the surface the platform never had: until now nobody could answer "what
 * files does this academy hold, how much space do they take, and which ones is
 * nothing using any more". Gated by `permission:files`, so a teacher can delegate
 * it to an assistant.
 */
class TeacherDocumentController
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentLinkResolver $links,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $documents = Document::query()
            ->with('owner')
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')->value()))
            ->when($request->filled('purpose'), fn ($q) => $q->where('purpose', $request->string('purpose')->value()))
            ->when($request->filled('q'), fn ($q) => $q->where('original_name', 'like', '%'.$request->string('q')->value().'%'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->orderBy(...$this->sort($request))
            ->paginate($request->integer('per_page') ?: 25)
            ->withQueryString();

        // "Unlinked only" cannot be a SQL filter: Pattern A links live on nine
        // other tables, so the answer comes from the resolver per row. Applied
        // after pagination, which is honest about cost — the tab's default view
        // is unfiltered, and this is the deliberate narrowing case.
        if ($request->boolean('linked') === false && $request->has('linked')) {
            $documents->setCollection(
                $documents->getCollection()->reject(fn (Document $d) => $this->links->isLinked($d))->values()
            );
        }

        return DocumentResource::collection($documents)
            ->additional(['meta' => ['filters' => $this->filterOptions()]]);
    }

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->documents->usageFor()->toArray()]);
    }

    public function show(Document $document): JsonResponse
    {
        return (new DocumentResource($document->load('owner')))->withLink()->response();
    }

    /**
     * Break the link between a file and whatever holds it, without deleting the
     * file. The teacher does this deliberately before deleting — see destroy().
     */
    public function unlink(Document $document): JsonResponse
    {
        $this->links->unlink($document);

        return (new DocumentResource($document->fresh()->load('owner')))->withLink()->response();
    }

    /**
     * Delete permanently. A file that something still points at is refused with
     * the holder named, so the teacher unlinks knowingly rather than silently
     * blanking a lesson part. There is no force flag: deletion cannot be undone,
     * so the two steps are the safeguard.
     */
    public function destroy(Document $document): Response|JsonResponse
    {
        $link = $this->links->resolve($document);

        if ($link !== null) {
            return response()->json([
                'error' => [
                    'code' => 'document_still_linked',
                    'message' => DocumentException::stillLinked()->getMessage(),
                    'linked_to' => $link->toArray(),
                ],
            ], 409);
        }

        $this->documents->delete($document);

        return response()->noContent();
    }

    // — helpers —

    /** @return array{0: string, 1: string} */
    private function sort(Request $request): array
    {
        $column = match ($request->string('sort')->value()) {
            'name' => 'original_name',
            'size' => 'size_bytes',
            'oldest' => 'created_at',
            default => 'created_at',
        };

        $direction = $request->string('sort')->value() === 'oldest' ? 'asc' : 'desc';

        return [$column, $column === 'original_name' ? 'asc' : $direction];
    }

    /** Drives the filter chips, so the UI never hard-codes the enum values. */
    private function filterOptions(): array
    {
        return [
            'kinds' => array_map(fn (DocumentKind $k) => $k->value, DocumentKind::cases()),
            'purposes' => array_map(fn (DocumentPurpose $p) => $p->value, DocumentPurpose::cases()),
        ];
    }
}

<?php

namespace App\Support\Files\Http\Controllers;

use App\Support\Files\DocumentLinkResolver;
use App\Support\Files\DocumentService;
use App\Support\Files\Enums\DocumentPurpose;
use App\Support\Files\Exceptions\DocumentException;
use App\Support\Files\Http\Resources\DocumentResource;
use App\Support\Files\Models\Document;
use App\Support\Files\StorageUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The student's own files: what they submitted, what they paid with, what they
 * attached to a question or a ticket.
 *
 * Deliberately narrower than the teacher's library. The list is scoped to files
 * the student uploaded themselves — a teacher's corrected return is readable
 * through the assignment it belongs to, but it is not the student's file to
 * manage, so it never appears here.
 */
class StudentDocumentController
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentLinkResolver $links,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $documents = Document::query()
            ->ownedBy((int) $request->user()->getKey())
            ->ofPurpose(DocumentPurpose::studentOwned())
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')->value()))
            ->when($request->filled('purpose'), fn ($q) => $q->where('purpose', $request->string('purpose')->value()))
            ->when($request->filled('q'), fn ($q) => $q->where('original_name', 'like', '%'.$request->string('q')->value().'%'))
            ->latest()
            ->paginate($request->integer('per_page') ?: 25)
            ->withQueryString();

        return DocumentResource::collection($documents);
    }

    public function summary(Request $request): JsonResponse
    {
        $usage = StorageUsage::forTenant(
            tenantId: null, // the tenant scope is already applied by the global scope
            ownerId: (int) $request->user()->getKey(),
        );

        return response()->json(['data' => $usage->toArray()]);
    }

    public function show(Request $request, Document $document): JsonResponse
    {
        $this->assertOwn($request, $document);

        return (new DocumentResource($document))->withLink()->response();
    }

    /**
     * Permanent delete, own unlinked files only. A submission still attached to
     * an attempt stays put — removing it would silently empty a graded answer.
     */
    public function destroy(Request $request, Document $document): Response|JsonResponse
    {
        $this->assertOwn($request, $document);

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

    private function assertOwn(Request $request, Document $document): void
    {
        if ($request->user()->cannot('deleteAsStudent', $document)) {
            throw new AccessDeniedHttpException('This file is not yours to manage.');
        }
    }
}

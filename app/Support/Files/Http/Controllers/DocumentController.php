<?php

namespace App\Support\Files\Http\Controllers;

use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Files\DocumentService;
use App\Support\Files\Http\Requests\StoreDocumentRequest;
use App\Support\Files\Http\Resources\DocumentResource;
use App\Support\Files\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The shared file endpoints, used by every role.
 *
 * Uploads land unattached: the client posts the file, gets a uuid back, and links
 * it when it creates the comment / ticket / submission that owns it. That split
 * is what lets a form upload progressively before the thing it belongs to exists.
 */
class DocumentController
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly TenantContext $context,
    ) {}

    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $purpose = $request->purpose();

        if ($purpose->requiresStaff() && ! $this->isStaff($request)) {
            throw new AccessDeniedHttpException('You may not upload this kind of file.');
        }

        $document = $this->documents->store($request->file('file'), $purpose);

        return (new DocumentResource($document))->response()->setStatusCode(201);
    }

    public function show(Request $request, Document $document): JsonResponse
    {
        $this->authorizeRead($request, $document);

        return (new DocumentResource($document->load('owner')))->withLink()->response();
    }

    /**
     * Serve the bytes. Two ways in: a valid signature (how `<img>`, `<iframe>`
     * and `<a download>` reach a private file, since they cannot send an
     * Authorization header), or an authenticated caller who passes the policy.
     * The signature is only ever minted after the policy has already said yes.
     */
    public function download(Request $request, Document $document): StreamedResponse
    {
        if (! $request->hasValidSignature()) {
            $this->authorizeRead($request, $document);
        }

        return $this->documents->download($document);
    }

    private function authorizeRead(Request $request, Document $document): void
    {
        // A public asset — a logo, a landing image — has to load for a visitor
        // who has not logged in yet, so it never reaches the policy.
        if ($document->isPublic()) {
            return;
        }

        $user = $request->user();

        if ($user === null || $user->cannot('view', $document)) {
            throw new AccessDeniedHttpException('You do not have access to this file.');
        }
    }

    private function isStaff(Request $request): bool
    {
        $tenant = $this->context->tenant();
        $role = $tenant !== null ? $request->user()?->membershipFor($tenant)?->role : null;

        return in_array($role, [TenantUserRole::Teacher, TenantUserRole::Assistant], true);
    }
}

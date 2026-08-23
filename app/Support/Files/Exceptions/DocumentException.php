<?php

namespace App\Support\Files\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A storage-layer failure that maps to a clean API envelope rather than a raw
 * 500. Messages stay user-safe: the disk name and storage key are useful to us
 * and useless-but-revealing to a caller, so they go to the log, never the wire.
 *
 * Mapped in App\Support\Http\ApiExceptionRenderer.
 */
class DocumentException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** The bytes could not be written — disk full, permissions, bad path → 500. */
    public static function writeFailed(string $key, string $disk): self
    {
        logger()->error('document.write_failed', ['key' => $key, 'disk' => $disk]);

        return new self('document_write_failed', __('The file could not be saved. Please try again.'), 500);
    }

    /** The row was removed but the blob refused to go; the transaction rolls back → 500. */
    public static function deleteFailed(string $key, string $disk): self
    {
        logger()->error('document.delete_failed', ['key' => $key, 'disk' => $disk]);

        return new self('document_delete_failed', __('The file could not be deleted. Please try again.'), 500);
    }

    /** A row exists but its blob is gone — a manual disk edit, or a failed write → 404. */
    public static function missingBlob(string $key, string $disk): self
    {
        logger()->warning('document.missing_blob', ['key' => $key, 'disk' => $disk]);

        return new self('document_missing', __('This file is no longer available.'), 404);
    }

    /** store() was called with no authenticated user and no explicit owner → 500 (a bug, not user input). */
    public static function noOwner(): self
    {
        return new self('document_no_owner', __('The file could not be saved.'), 500);
    }

    /**
     * No tenant to file the document under. Always a bug: either the request
     * never resolved a tenant, or a signature-authenticated path forgot to pass
     * one explicitly through StoreOptions.
     */
    public static function noTenant(): self
    {
        return new self('document_no_tenant', __('The file could not be saved.'), 500);
    }

    /** Deleting a document that something still points at → 409, with the link surfaced by the controller. */
    public static function stillLinked(): self
    {
        return new self(
            'document_still_linked',
            __('This file is still attached. Unlink it before deleting.'),
            409,
        );
    }
}

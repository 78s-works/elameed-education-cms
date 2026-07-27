<?php

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * A business-rule rejection that maps to the API error envelope with a specific,
 * machine-readable `code` (so the SPA can react — e.g. show an upgrade prompt on
 * `plan_limit_reached`), rather than the generic `forbidden`/`validation_error`.
 *
 * Rendered by App\Support\Http\ApiExceptionRenderer. Mirrors the MediaException
 * pattern (errorCode + status) but is domain-agnostic and reusable across modules.
 *
 * @property-read string $errorCode
 */
class DomainException extends RuntimeException
{
    /**
     * @param  string  $errorCode  Machine code for the error envelope (snake_case).
     * @param  string  $publicMessage  Human message safe to return to the client.
     * @param  int  $status  HTTP status code.
     * @param  array<string, mixed>  $details  Optional structured details.
     */
    public function __construct(
        public readonly string $errorCode,
        string $publicMessage,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($publicMessage);
    }
}

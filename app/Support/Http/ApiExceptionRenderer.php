<?php

namespace App\Support\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Maps exceptions to the API error envelope (04_API_Specification.md §1):
 *
 *   { "error": { "code": "...", "message": "...", "details": { } } }
 *
 * Only engages for API / JSON requests; web requests fall back to the default
 * handler. Returns null to let a request pass through untouched.
 */
final class ApiExceptionRenderer
{
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        if ($e instanceof ValidationException) {
            return self::envelope('validation_error', $e->getMessage(), 422, $e->errors());
        }

        if ($e instanceof AuthenticationException) {
            return self::envelope('unauthenticated', $e->getMessage(), 401);
        }

        if ($e instanceof AuthorizationException) {
            return self::envelope('forbidden', $e->getMessage() ?: 'Forbidden.', 403);
        }

        if ($e instanceof ModelNotFoundException) {
            return self::envelope('not_found', 'Resource not found.', 404);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = $e->getMessage();

            // Route-model binding wraps ModelNotFoundException in a 404 whose
            // message leaks the internal model class + raw id ("No query results
            // for model […] undefined"). Replace it with a clean message.
            if ($status === 404 && ($message === '' || str_starts_with($message, 'No query results'))) {
                $message = 'Resource not found.';
            }

            return self::envelope(self::codeForStatus($status), $message ?: self::messageForStatus($status), $status);
        }

        // Unexpected error: never leak internals in production.
        $message = config('app.debug') ? $e->getMessage() : 'Server error.';

        return self::envelope('server_error', $message, 500);
    }

    private static function envelope(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status);
    }

    private static function codeForStatus(int $status): string
    {
        return match ($status) {
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            412 => 'precondition_failed',
            429 => 'too_many_requests',
            default => $status >= 500 ? 'server_error' : 'error',
        };
    }

    private static function messageForStatus(int $status): string
    {
        return match ($status) {
            403 => 'Forbidden.',
            404 => 'Not found.',
            405 => 'Method not allowed.',
            412 => 'Precondition failed.',
            429 => 'Too many requests.',
            default => 'Request failed.',
        };
    }
}

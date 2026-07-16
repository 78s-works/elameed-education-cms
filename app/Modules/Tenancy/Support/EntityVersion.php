<?php

namespace App\Modules\Tenancy\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Optimistic-concurrency helper for the teacher editor endpoints
 * (PUT /teacher/profile, PUT /teacher/landing). Both write the single
 * teacher_profiles row as a whole, so two editors saving at once would let the
 * last write silently clobber the first (lost update).
 *
 * The fix is HTTP-native: GET returns an `ETag` derived from the row's identity
 * + last-modified time; a write may echo it back as `If-Match`. If the row has
 * changed since (ETag no longer matches), the write is rejected with 412 so the
 * client can reload and retry instead of overwriting.
 *
 * If-Match is OPT-IN — a request that sends no If-Match header skips the check,
 * so existing clients keep working unchanged.
 *
 * Note: the token is second-granular (from `updated_at`), which is ample for a
 * human-driven editor; it is not meant to serialize sub-second machine writes.
 */
final class EntityVersion
{
    /**
     * A quoted ETag for a model. An unsaved model (no key / no timestamp) yields
     * a stable "new" token, so a client editing a not-yet-created profile can
     * still take part in the If-Match handshake.
     */
    public static function etag(?Model $model): string
    {
        $key = $model?->getKey();
        $timestamp = $model?->updated_at?->toIso8601String();

        return '"'.sha1(($key ?? 'new').'|'.($timestamp ?? '')).'"';
    }

    /**
     * Reject the write with 412 when the request carries an If-Match that no
     * longer matches the current version. No If-Match header → no-op.
     */
    public static function assertMatches(Request $request, ?Model $model): void
    {
        $ifMatch = trim((string) $request->header('If-Match'));

        if ($ifMatch === '') {
            return;
        }

        if ($ifMatch !== self::etag($model)) {
            throw new HttpException(412, 'This resource was modified since you loaded it. Reload and try again.');
        }
    }
}

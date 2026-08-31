<?php

namespace App\Http\Middleware;

use App\Modules\PlatformAdmin\Http\Controllers\ImpersonationController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Impersonation is read-only (ADM-17), and this is what makes that true rather
 * than merely intended: a token minted for viewing an academy carries exactly
 * one ability, and any request that could change state is refused here — before
 * the controller, so no route can forget to opt in.
 *
 * Ending the session is the single exception: an admin must always be able to
 * get out, and stopping revokes the token rather than writing anything else.
 */
class BlockImpersonatedWrites
{
    /** Methods that cannot change state. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // Read the raw ability list rather than `can()`: an impersonation token
        // deliberately also carries `*` so the owner's own permission gates keep
        // passing on reads, and `can()` would answer true for anything.
        $abilities = is_array($token?->abilities ?? null) ? $token->abilities : [];
        $isImpersonating = in_array(ImpersonationController::ABILITY, $abilities, true);

        if (
            $isImpersonating
            && ! in_array($request->method(), self::SAFE_METHODS, true)
            && ! $request->is('api/*/impersonation/stop')
        ) {
            return response()->json([
                'error' => [
                    'code' => 'impersonation_read_only',
                    'message' => __('أنت تشاهد لوحة الأكاديمية للاطّلاع فقط. لا يمكن تنفيذ أي تعديل أثناء هذه الجلسة.'),
                ],
            ], 403);
        }

        return $next($request);
    }
}

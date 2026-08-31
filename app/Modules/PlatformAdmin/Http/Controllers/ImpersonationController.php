<?php

namespace App\Modules\PlatformAdmin\Http\Controllers;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Supervised impersonation (ADM-17). Support can read a teacher's own panel
 * instead of diagnosing a problem over the phone from a description.
 *
 * Three properties make this safe enough to exist:
 *   - READ ONLY. The minted token carries a single ability, and
 *     {@see \App\Http\Middleware\BlockImpersonatedWrites} refuses every
 *     non-GET request it is used on. A write-enabled mode is deliberately not
 *     built here.
 *   - SHORT LIVED and single-purpose: one token per session, revoked on exit.
 *   - AUDITED at both ends, naming the admin and the academy.
 */
class ImpersonationController
{
    /** The only ability an impersonation token ever carries. */
    public const ABILITY = 'impersonate:read-only';

    /** Minutes before an unattended impersonation session dies on its own. */
    private const TTL_MINUTES = 60;

    /** Token name prefix; the admin's id is appended. See start(). */
    private const TOKEN_PREFIX = 'impersonation:';

    public function start(Tenant $tenant): JsonResponse
    {
        $admin = Auth::user();
        $owner = $tenant->owner;

        if ($owner === null) {
            throw new NotFoundHttpException('This academy has no owner account to view as.');
        }

        // `*` keeps the owner's normal permission gates passing, so the panel
        // renders exactly what the teacher sees; the marker ability alongside it
        // is what BlockImpersonatedWrites refuses every write on. Reading has to
        // work through the same gates, or the view would be a different panel.
        // The admin's id rides in the token NAME so the exit event can be
        // attributed to them: `stop` is called with the OWNER's token, and an
        // audit trail that says the teacher ended the session would be wrong
        // about the one fact the entry exists to record.
        $token = $owner->createToken(
            self::TOKEN_PREFIX.$admin?->getKey(),
            ['*', self::ABILITY],
            now()->addMinutes(self::TTL_MINUTES),
        );

        app(AuditLogger::class)->log(
            'tenant.impersonation.started',
            [
                'admin' => $admin?->name,
                'academy' => $tenant->name,
                'viewed_as' => $owner->name,
                'read_only' => true,
            ],
            (int) $tenant->getKey(),
            'tenant',
            (int) $tenant->getKey(),
            $admin?->getKey(),
        );

        return response()->json(['data' => [
            'token' => $token->plainTextToken,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->toIso8601String(),
            'read_only' => true,
            'tenant' => [
                'uuid' => $tenant->uuid,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'host' => $tenant->domains()->where('is_primary', true)->value('host'),
            ],
            'owner' => ['name' => $owner->name],
        ]]);
    }

    /**
     * End the session. Called with the impersonation token itself, so the token
     * being revoked is the one making the request — an admin cannot end someone
     * else's session by accident, and a dead token cannot be reused.
     */
    public function stop(): JsonResponse
    {
        /** @var User|null $user */
        $user = Auth::user();
        $token = $user?->currentAccessToken();

        $adminId = str_starts_with((string) $token?->name, self::TOKEN_PREFIX)
            ? (int) substr((string) $token->name, strlen(self::TOKEN_PREFIX))
            : null;

        app(AuditLogger::class)->log(
            'tenant.impersonation.ended',
            ['viewed_as' => $user?->name],
            null,
            null,
            null,
            $adminId ?: null,
        );

        $token?->delete();

        return response()->json(['data' => ['ended' => true]]);
    }
}

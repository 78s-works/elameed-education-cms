<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Models\LoginAttempt;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /me/login-history — the calling student's own recent sign-ins: when, from
 * which IP, and on what device, newest first.
 *
 * Successful attempts only. A failed attempt is a security signal the teacher's
 * activity view already shows; putting failures in the student's own list would
 * mostly show them their own typos, and would tell an attacker holding a session
 * whether their earlier guesses were even seen.
 */
class StudentLoginHistoryController
{
    /** Cap on rows returned, whatever the client asks for. */
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        $rows = LoginAttempt::query()
            ->where('tenant_id', $this->context->tenantOrFail()->getKey())
            ->where('user_id', $request->user()->getKey())
            ->where('success', true)
            ->latest('created_at')
            ->limit((int) $request->integer('limit', 20))
            ->get(['ip', 'user_agent', 'created_at'])
            ->map(fn (LoginAttempt $attempt) => [
                'at' => $attempt->created_at?->toIso8601String(),
                'ip' => $attempt->ip,
                'user_agent' => $attempt->user_agent,
                // A short label for the row ("Chrome · Windows"); the raw agent
                // stays alongside it so the client can show the full string.
                'device' => $this->device($attempt->user_agent),
            ]);

        return response()->json(['data' => $rows->all()]);
    }

    /**
     * Coarse "browser · platform" label from a user-agent string. Deliberately
     * simple pattern matching, not a UA-parsing dependency: this is a hint for the
     * student ("was that me?"), and an unrecognised agent is better shown as null
     * than guessed at.
     */
    private function device(?string $agent): ?string
    {
        if ($agent === null || trim($agent) === '') {
            return null;
        }

        $browsers = [
            'Edg' => 'Edge',
            'OPR' => 'Opera',
            'Chrome' => 'Chrome',
            'Firefox' => 'Firefox',
            'Safari' => 'Safari',
        ];
        $platforms = [
            'Android' => 'Android',
            'iPhone' => 'iPhone',
            'iPad' => 'iPad',
            'Windows' => 'Windows',
            'Mac OS' => 'macOS',
            'Linux' => 'Linux',
        ];

        $browser = null;
        foreach ($browsers as $needle => $label) {
            if (str_contains($agent, $needle)) {
                $browser = $label;
                break;
            }
        }

        $platform = null;
        foreach ($platforms as $needle => $label) {
            if (str_contains($agent, $needle)) {
                $platform = $label;
                break;
            }
        }

        $parts = array_filter([$browser, $platform]);

        return $parts === [] ? null : implode(' · ', $parts);
    }
}

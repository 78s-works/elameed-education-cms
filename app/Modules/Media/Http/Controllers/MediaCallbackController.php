<?php

namespace App\Modules\Media\Http\Controllers;

use App\Modules\Media\Models\MediaCallbackEvent;
use App\Modules\Media\Services\RemoteVideoService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Ingests signed processing callbacks from the Media Host
 * (docs/MEDIA_HOST_API_v1.md §1.2/§4). No tenant/bearer — the HMAC signature IS
 * the authentication. Verifies signature + timestamp skew, dedupes by
 * X-Media-Event-Id (replay-safe), then applies the state change (transition
 * guarded by the service). Never logs the raw body or the signature.
 */
class MediaCallbackController
{
    public function __construct(private readonly RemoteVideoService $service) {}

    public function processing(Request $request): JsonResponse
    {
        $this->verifySignature($request);

        $eventId = (string) $request->header('X-Media-Event-Id');
        $payload = (array) $request->json()->all();

        // Fast-path dedupe; the unique index closes the race below.
        if (MediaCallbackEvent::where('event_id', $eventId)->exists()) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        try {
            DB::transaction(function () use ($eventId, $payload): void {
                MediaCallbackEvent::create([
                    'event_id' => $eventId,
                    'type' => $payload['type'] ?? null,
                    'payload_hash' => hash('sha256', (string) json_encode($payload)),
                ]);
                $version = $this->service->applyCallback($payload);
                MediaCallbackEvent::where('event_id', $eventId)->update([
                    'tenant_id' => $version->tenant_id,
                    'media_version_id' => $version->getKey(),
                    'processed_at' => now(),
                ]);
            });
        } catch (QueryException $e) {
            // Duplicate event id inserted concurrently → already handled.
            if (str_contains(strtolower($e->getMessage()), 'unique') || (int) ($e->errorInfo[1] ?? 0) === 1062) {
                return response()->json(['received' => true, 'duplicate' => true]);
            }
            throw $e;
        }

        return response()->json(['received' => true]);
    }

    private function verifySignature(Request $request): void
    {
        $secret = (string) config('media.host.callback_secret');
        $timestamp = (string) $request->header('X-Media-Timestamp', '');
        $signature = (string) $request->header('X-Media-Signature', '');
        $eventId = (string) $request->header('X-Media-Event-Id', '');

        if ($secret === '' || $timestamp === '' || $signature === '' || $eventId === '') {
            throw new AccessDeniedHttpException('Missing callback authentication.');
        }
        if (abs(time() - (int) $timestamp) > 300) {
            throw new AccessDeniedHttpException('Callback timestamp is stale.');
        }

        $expected = base64_encode(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret, true));
        if (! hash_equals($expected, $signature)) {
            throw new AccessDeniedHttpException('Invalid callback signature.');
        }
    }
}

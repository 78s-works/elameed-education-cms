<?php

namespace App\Modules\Media\Providers;

use App\Modules\Media\Contracts\MediaHostProvider;
use App\Modules\Media\Exceptions\MediaHostException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * HTTP client for the OVH Media Host, implementing the v1 contract
 * (docs/MEDIA_HOST_API_v1.md). Signs every request with HMAC (§1.1), applies
 * timeouts + SSL verification, carries the Idempotency-Key, retries idempotent
 * calls on transient network failure, and maps host errors to MediaHostException.
 * Uses Laravel's Http facade so tests fake it with Http::fake() — no real host.
 * Never logs credentials, signatures, tokens, or bodies.
 */
class RemoteMediaProvider implements MediaHostProvider
{
    /** @var array<string,mixed> */
    private array $cfg;

    public function __construct()
    {
        $this->cfg = (array) config('media.host', []);
    }

    public function name(): string
    {
        return 'remote';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->cfg['base_url'])
            && ! empty($this->cfg['api_key'])
            && ! empty($this->cfg['api_secret']);
    }

    public function health(): array
    {
        return $this->request('GET', "/{$this->version()}/health");
    }

    public function createUpload(array $payload, string $idempotencyKey): array
    {
        return $this->request('POST', "/{$this->version()}/uploads", $payload, $idempotencyKey);
    }

    public function completeUpload(string $uploadId): array
    {
        return $this->request('POST', "/{$this->version()}/uploads/{$uploadId}/complete");
    }

    public function startProcessing(string $uploadId, array $options, string $idempotencyKey): array
    {
        return $this->request('POST', "/{$this->version()}/uploads/{$uploadId}/process", $options, $idempotencyKey);
    }

    public function quarantine(string $hostVideoId): array
    {
        return $this->request('POST', "/{$this->version()}/videos/{$hostVideoId}/quarantine", [], (string) Str::uuid());
    }

    public function restore(string $hostVideoId): array
    {
        return $this->request('POST', "/{$this->version()}/videos/{$hostVideoId}/restore", [], (string) Str::uuid());
    }

    public function purge(string $hostVideoId): array
    {
        return $this->request('DELETE', "/{$this->version()}/videos/{$hostVideoId}", null, (string) Str::uuid());
    }

    private function version(): string
    {
        return (string) ($this->cfg['api_version'] ?? 'v1');
    }

    /**
     * Signed request → array response, or throws MediaHostException.
     */
    private function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        if (! $this->isConfigured()) {
            throw MediaHostException::notConfigured();
        }

        $timestamp = (string) time();
        $nonce = (string) Str::uuid();
        $bodyJson = $body !== null ? (string) json_encode($body) : '';
        $signingString = implode("\n", [strtoupper($method), $path, $timestamp, $nonce, hash('sha256', $bodyJson)]);

        $headers = [
            'X-Media-Api-Key' => (string) $this->cfg['api_key'],
            'X-Media-Api-Version' => $this->version(),
            'X-Media-Timestamp' => $timestamp,
            'X-Media-Nonce' => $nonce,
            'X-Media-Signature' => base64_encode(hash_hmac('sha256', $signingString, (string) $this->cfg['api_secret'], true)),
            'Accept' => 'application/json',
        ];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $client = Http::baseUrl((string) $this->cfg['base_url'])
            ->withHeaders($headers)
            ->connectTimeout((int) ($this->cfg['connect_timeout'] ?? 10))
            ->timeout((int) ($this->cfg['request_timeout'] ?? 30))
            ->withOptions(['verify' => (bool) ($this->cfg['verify_ssl'] ?? true)]);

        // Idempotent requests (GET, or anything carrying an Idempotency-Key) may
        // retry transient network failures; non-idempotent ones never do.
        if ($method === 'GET' || $idempotencyKey !== null) {
            $client = $client->retry(3, 200, throw: false);
        }

        try {
            $response = $client->send($method, $path, $body !== null ? ['json' => $body] : []);
        } catch (\Throwable $e) {
            throw new MediaHostException('network_error', 'Media Host is unreachable.', null, true, $e);
        }

        Log::debug('media-host request', ['method' => $method, 'path' => $path, 'status' => $response->status()]);

        if ($response->successful()) {
            return (array) $response->json();
        }

        $status = $response->status();

        throw new MediaHostException(
            (string) ($response->json('error.code') ?? $this->codeForStatus($status)),
            (string) ($response->json('error.message') ?? 'Media Host request failed.'),
            $status,
            $status >= 500 || $status === 429,
        );
    }

    private function codeForStatus(int $status): string
    {
        return match (true) {
            $status === 400 => 'invalid_request',
            $status === 401 => 'unauthorized',
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 409 => 'conflict',
            $status === 422 => 'unprocessable',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'internal',
            default => 'error',
        };
    }
}

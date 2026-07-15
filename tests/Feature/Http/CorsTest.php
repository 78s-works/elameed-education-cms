<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * CORS allow-list behaviour (stock HandleCors + config/cors.php). Production
 * origins come from CORS_ALLOWED_ORIGINS; local development additionally allows
 * any private-LAN origin so Vite dev servers on changing LAN IPs work.
 */
class CorsTest extends TestCase
{
    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/v1/tenant/context', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,x-tenant',
        ]);
    }

    public function test_private_lan_origin_is_allowed_in_non_production(): void
    {
        $this->preflight('http://192.168.100.23:5173')
            ->assertHeader('Access-Control-Allow-Origin', 'http://192.168.100.23:5173');
    }

    public function test_localhost_origin_is_allowed(): void
    {
        $this->preflight('http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function test_public_internet_origin_not_in_the_list_is_rejected(): void
    {
        // The dev pattern only matches PRIVATE LAN ranges — a public origin is
        // never waved through (and in production the pattern is off entirely).
        $this->preflight('https://evil.example.com')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}

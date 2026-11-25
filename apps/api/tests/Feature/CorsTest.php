<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** SECURITY.md S5: a browser may call the API only from Evenbooks' own web app, never with credentials. */
final class CorsTest extends TestCase
{
    use RefreshLedgerDatabase;

    private const PREFLIGHT = ['Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'authorization,content-type'];

    public function test_another_sites_page_gets_no_permission_to_call_the_api(): void
    {
        // The answer names only Evenbooks' own web app (or nothing), so the browser refuses the other site's page.
        $own = rtrim((string) config('security.frontend_url'), '/');
        $preflight = $this->call('OPTIONS', '/api/v1/auth/login', server: $this->server(['Origin' => 'https://evil.example', ...self::PREFLIGHT]));
        $this->assertContains($preflight->headers->get('Access-Control-Allow-Origin'), [null, $own]);
        $this->assertNull($preflight->headers->get('Access-Control-Allow-Credentials'));

        $response = $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/v1/auth/me');
        $this->assertContains($response->headers->get('Access-Control-Allow-Origin'), [null, $own]);
    }

    public function test_the_web_app_may_and_without_credentials(): void
    {
        $origin = rtrim((string) config('security.frontend_url'), '/');
        $this->assertSame([$origin], config('cors.allowed_origins'));

        $preflight = $this->call('OPTIONS', '/api/v1/auth/login', server: $this->server(['Origin' => $origin, ...self::PREFLIGHT]));
        $this->assertSame($origin, $preflight->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($preflight->headers->get('Access-Control-Allow-Credentials'));
        $this->assertStringNotContainsString('*', (string) $preflight->headers->get('Access-Control-Allow-Origin'));
    }

    /** @param array<string, string> $headers */
    private function server(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * The API answers in JSON with its security headers, whatever the client sends. Found by the smoke test of
 * the production image: a request without `Accept: application/json` and without a token got a 500
 * ("Route [login] not defined") instead of a 401.
 */
final class ApiResponsesTest extends TestCase
{
    use RefreshLedgerDatabase;

    public function test_an_unauthenticated_request_gets_401_json_without_an_accept_header(): void
    {
        foreach (['/api/v1/companies', '/api/v1/invoices'] as $uri) {
            $this->get($uri)
                ->assertStatus(401)
                ->assertHeader('Content-Type', 'application/json')
                ->assertExactJson(['message' => 'Unauthenticated.']);
        }
    }

    public function test_validation_errors_are_json_not_a_redirect(): void
    {
        $this->post('/api/v1/auth/register', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_an_unknown_route_is_a_plain_json_404(): void
    {
        $this->get('/api/v1/no-such-thing')->assertStatus(404)->assertExactJson(['message' => 'Not found.']);
    }

    public function test_error_responses_carry_the_security_headers(): void
    {
        // HSTS means something only over HTTPS, so it is sent only there.
        $response = $this->get('https://localhost/api/v1/companies')->assertStatus(401);

        foreach (['X-Content-Type-Options', 'X-Frame-Options', 'Content-Security-Policy', 'Strict-Transport-Security', 'Cache-Control'] as $header) {
            $this->assertTrue($response->headers->has($header), "401 response is missing {$header}");
        }
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}

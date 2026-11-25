<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * The general API limit: 300 requests a minute per signed-in session (AppServiceProvider::API_REQUESTS_PER_MINUTE),
 * each person counted alone. The sign-in limit (10 a minute per visitor) is SignInLimitsTest.
 */
final class ApiRateLimitTest extends TestCase
{
    use RefreshLedgerDatabase;

    public function test_a_signed_in_session_may_make_300_requests_a_minute(): void
    {
        $token = $this->createUser('Selam Tesfaye', 'selam@example.test')->issueToken();

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '300')
            ->assertHeader('X-RateLimit-Remaining', '299');
    }

    public function test_each_person_has_their_own_count(): void
    {
        $selam = $this->createUser('Selam Tesfaye', 'selam@example.test')->issueToken();
        $abel = $this->createUser('Abel Haile', 'abel@example.test')->issueToken();

        for ($i = 0; $i < 3; $i++) {
            $this->as($selam)->getJson('/api/v1/auth/me')->assertOk();
        }
        $this->as($abel)->getJson('/api/v1/auth/me')->assertOk()->assertHeader('X-RateLimit-Remaining', '299');
        $this->as($selam)->getJson('/api/v1/auth/me')->assertOk()->assertHeader('X-RateLimit-Remaining', '296');
    }

    /** The next request is made with this token (and not as whoever made the previous one). */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}

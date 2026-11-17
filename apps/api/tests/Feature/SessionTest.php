<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 11: the devices someone is signed in on. Each sign-in remembers a short device name and the address;
 * the web server's word for them counts only with its key. One sees and ends only one's own sessions.
 */
final class SessionTest extends TestCase
{
    use RefreshLedgerDatabase;

    private const KEY = 'test-web-server-key-0123456789';
    private const PHONE = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Mobile Safari/537.36';
    private const LAPTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0';

    public function test_each_sign_in_shows_its_device_and_address_and_only_the_web_server_may_name_them(): void
    {
        config(['security.web_server_key' => self::KEY]);
        $this->createUser('Hana', 'hana@example.test', withAuthenticator: false);

        $this->signIn(['X-Ledgerly-Web-Key' => self::KEY, 'X-Ledgerly-Client-Address' => '196.188.10.20', 'X-Ledgerly-Client-Agent' => self::PHONE]);
        // Without the key the headers are ignored: the request's own browser and address count.
        $laptop = $this->signIn(['X-Ledgerly-Client-Address' => '10.9.9.9', 'X-Ledgerly-Client-Agent' => self::PHONE, 'User-Agent' => self::LAPTOP]);

        $sessions = collect($this->as($laptop)->getJson('/api/v1/auth/sessions')->assertOk()->json('data'))->keyBy('device');
        $this->assertSame(['Chrome on Android', 'Firefox on Windows'], $sessions->keys()->sort()->values()->all());
        $this->assertSame('196.188.10.20', $sessions['Chrome on Android']['address']);
        $this->assertSame('127.0.0.1', $sessions['Firefox on Windows']['address']);
        $this->assertTrue($sessions['Firefox on Windows']['current']);
        $this->assertFalse($sessions['Chrome on Android']['current']);
        $this->assertNotNull($sessions['Chrome on Android']['expires_at']);
        $this->assertSame(0, $this->owner()->table('personal_access_tokens')->where('device', 'like', '%Mozilla%')->count(), 'only the short name is kept');
    }

    public function test_one_ends_another_of_ones_own_sessions_but_not_this_one_nor_anyone_elses(): void
    {
        $hana = $this->createUser('Hana', 'hana@example.test', withAuthenticator: false);
        $abebe = $this->createUser('Abebe', 'abebe@example.test', withAuthenticator: false);
        $phone = $this->signIn(['User-Agent' => self::PHONE]);
        $laptop = $this->signIn(['User-Agent' => self::LAPTOP]);
        $abebes = $abebe->issueToken();
        $hana->tokens()->create(['name' => 'web', 'token' => hash('sha256', 'an-old-one'), 'abilities' => ['*'], 'expires_at' => now()->subHour()]);

        $list = collect($this->as($laptop)->getJson('/api/v1/auth/sessions')->assertOk()->json('data'));
        $this->assertCount(2, $list, 'expired sessions and other people\'s are not listed');
        $phoneId = $list->firstWhere('current', false)['id'];
        $laptopId = $list->firstWhere('current', true)['id'];
        $abebesId = (string) $this->owner()->table('personal_access_tokens')->where('tokenable_id', $abebe->getKey())->value('id');

        $this->as($laptop)->deleteJson("/api/v1/auth/sessions/{$laptopId}")->assertUnprocessable()->assertJsonValidationErrors('session');
        $this->as($laptop)->deleteJson("/api/v1/auth/sessions/{$abebesId}")->assertNotFound();
        $this->as($laptop)->deleteJson('/api/v1/auth/sessions/not-a-number')->assertNotFound();
        $this->as($abebes)->getJson('/api/v1/auth/me')->assertOk();

        $this->as($laptop)->deleteJson("/api/v1/auth/sessions/{$phoneId}")->assertNoContent();
        $this->as($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->as($laptop)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertCount(1, $this->as($laptop)->getJson('/api/v1/auth/sessions')->json('data'));
    }

    /** @param array<string, string> $headers */
    private function signIn(array $headers): string
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        return $this->withHeaders($headers)->postJson('/api/v1/auth/login', ['email' => 'hana@example.test', 'password' => 'secret-password'])
            ->assertOk()->json('token');
    }

    /** The next request is made with this token alone. */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        return $this->withToken($token);
    }
}

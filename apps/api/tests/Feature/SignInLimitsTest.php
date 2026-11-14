<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ClientAddress;
use Illuminate\Http\Request;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * SECURITY.md S16: sign-in limits count each visitor, not the web server that signs them in, and each limit
 * has its own counter (10 sign-in requests a minute means 10, not 5).
 */
final class SignInLimitsTest extends TestCase
{
    use RefreshLedgerDatabase;

    private const KEY = 'web-server-key-for-tests';

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.web_server_key' => self::KEY]);
    }

    public function test_the_sign_in_limit_is_ten_a_minute_and_counted_once(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => "nobody{$i}@example.test", 'password' => 'wrong-password'])
                ->assertStatus(422)
                ->assertHeader('X-RateLimit-Remaining', (string) (10 - $i));
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody11@example.test', 'password' => 'wrong-password'])->assertStatus(429);

        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody12@example.test', 'password' => 'wrong-password'])->assertStatus(422);
    }

    public function test_one_visitors_attempts_do_not_count_against_another(): void
    {
        $from = fn (string $address) => ['X-Ledgerly-Web-Key' => self::KEY, 'X-Ledgerly-Client-Address' => $address];

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => "nobody{$i}@example.test", 'password' => 'wrong-password'], $from('203.0.113.7'))->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password'], $from('203.0.113.7'))->assertStatus(429);

        // Through the same web server, a different person is not held up.
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password'], $from('198.51.100.20'))->assertStatus(422);
    }

    public function test_the_visitor_address_is_believed_only_from_the_web_server(): void
    {
        $request = fn (array $headers) => tap(Request::create('/api/v1/auth/login', 'POST', server: ['REMOTE_ADDR' => '192.0.2.1']),
            fn (Request $r) => $r->headers->add($headers));

        $this->assertSame('203.0.113.7', ClientAddress::of($request(['X-Ledgerly-Web-Key' => self::KEY, 'X-Ledgerly-Client-Address' => '203.0.113.7'])));
        $this->assertSame('2001:db8::7', ClientAddress::of($request(['X-Ledgerly-Web-Key' => self::KEY, 'X-Ledgerly-Client-Address' => '2001:db8::7'])));

        // No key, a wrong key, or something that is not an address: the caller's own address is used.
        $this->assertSame('192.0.2.1', ClientAddress::of($request(['X-Ledgerly-Client-Address' => '203.0.113.7'])));
        $this->assertSame('192.0.2.1', ClientAddress::of($request(['X-Ledgerly-Web-Key' => 'guess', 'X-Ledgerly-Client-Address' => '203.0.113.7'])));
        $this->assertSame('192.0.2.1', ClientAddress::of($request(['X-Ledgerly-Web-Key' => self::KEY, 'X-Ledgerly-Client-Address' => 'not-an-address'])));

        // With no key configured nothing is believed, even an empty key.
        config(['security.web_server_key' => '']);
        $this->assertSame('192.0.2.1', ClientAddress::of($request(['X-Ledgerly-Web-Key' => '', 'X-Ledgerly-Client-Address' => '203.0.113.7'])));
    }

    public function test_a_caller_without_the_key_cannot_escape_the_limit_by_claiming_other_addresses(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => "nobody{$i}@example.test", 'password' => 'wrong-password'], ['X-Ledgerly-Client-Address' => "203.0.113.{$i}"])
                ->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password'], ['X-Ledgerly-Client-Address' => '203.0.113.99'])
            ->assertStatus(429);
    }
}

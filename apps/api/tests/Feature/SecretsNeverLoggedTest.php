<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Totp;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * SECURITY.md S10: sign-ins, failures and account changes are logged, and no log line ever holds a password, a
 * token, a sign-in challenge, an authenticator secret or code, a recovery code or a reset link.
 */
final class SecretsNeverLoggedTest extends TestCase
{
    use RefreshLedgerDatabase;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->lines[] = $e->level . ' ' . $e->message . ' ' . json_encode($e->context);
        });
    }

    public function test_a_whole_account_life_leaves_no_secret_in_the_log(): void
    {
        $secrets = [];
        $first = 'First-Long-Password-1';
        $wrong = 'Wrong-Guess-Password-9';
        $secrets[] = $first;
        $secrets[] = $wrong;

        $secrets[] = $token = $this->postJson('/api/v1/auth/register', ['name' => 'Hana', 'email' => 'hana@example.test', 'password' => $first])->assertCreated()->json('token');
        $user = User::query()->where('email', 'hana@example.test')->sole();
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->signedOut();
        $this->postJson('/api/v1/auth/login', ['email' => 'hana@example.test', 'password' => $wrong])->assertUnprocessable();
        $secrets[] = $token = $this->postJson('/api/v1/auth/login', ['email' => 'hana@example.test', 'password' => $first])->assertOk()->json('token');

        // An authenticator app, then a sign-in with a code.
        $this->signedOut();
        $secrets[] = $secret = $this->withToken($token)->postJson('/api/v1/auth/mfa/enrol')->assertOk()->json('secret');
        $secrets[] = $code = Totp::codeAt($secret, Totp::stepAt(time()));
        $recovery = $this->withToken($token)->postJson('/api/v1/auth/mfa/confirm', ['code' => $code])->assertOk()->json('recovery_codes');
        array_push($secrets, ...$recovery);

        $this->signedOut();
        $secrets[] = $challenge = $this->postJson('/api/v1/auth/login', ['email' => 'hana@example.test', 'password' => $first])->assertOk()->json('challenge');
        $this->postJson('/api/v1/auth/login/mfa', ['challenge' => $challenge, 'code' => '000000'])->assertUnprocessable();
        $secrets[] = $token = $this->postJson('/api/v1/auth/login/mfa', ['challenge' => $challenge, 'recovery_code' => $recovery[0]])->assertOk()->json('token');

        // A reset link, a new password, then a changed one.
        $this->signedOut();
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'hana@example.test'])->assertOk();
        $secrets[] = $link = Notification::sent($user, ResetPasswordLink::class)->first()->token;
        $secrets[] = $second = 'Second-Long-Password-2';
        $this->postJson('/api/v1/auth/password/reset', ['token' => $link, 'password' => $second])->assertOk();
        $this->signedOut();
        $challenge = $this->postJson('/api/v1/auth/login', ['email' => 'hana@example.test', 'password' => $second])->assertOk()->json('challenge');
        $secrets[] = $challenge;
        $secrets[] = $token = $this->postJson('/api/v1/auth/login/mfa', ['challenge' => $challenge, 'recovery_code' => $recovery[1]])->assertOk()->json('token');
        $this->signedOut();
        $secrets[] = $third = 'Third-Long-Password-3';
        $this->withToken($token)->postJson('/api/v1/auth/password', ['current_password' => $second, 'password' => $third])->assertOk();

        $log = implode("\n", $this->lines);
        foreach (['auth.login_failed', 'auth.login', 'auth.mfa_failed', 'mfa.enabled', 'password.reset', 'password.changed'] as $event) {
            $this->assertStringContainsString($event, $log, "{$event} is not logged");
        }
        $this->assertStringNotContainsString('hana@example.test', $log, 'a failed sign-in keeps a hash of the address typed, not the address');
        foreach (array_unique($secrets) as $s) {
            // Each secret whole, and its part after a token's "id|" (a token's secret half).
            foreach (array_filter([$s, str_contains($s, '|') ? substr($s, strpos($s, '|') + 1) : null]) as $needle) {
                $this->assertStringNotContainsString($needle, $log, 'a secret reached the log');
            }
        }
    }

    private function signedOut(): void
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
    }
}

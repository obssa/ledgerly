<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Totp;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * SECURITY.md S2: an authenticator code at sign-in. The password alone gives no token to someone who has set
 * one up; a code works once; wrong codes are limited; recovery codes are one-time and stored hashed; owners
 * and admins cannot work in a company without it.
 */
final class MfaTest extends TestCase
{
    use RefreshLedgerDatabase;

    private const PASSWORD = 'secret-password';

    public function test_setting_up_shows_a_secret_and_the_first_correct_code_turns_it_on(): void
    {
        $user = $this->createUser('Hana', 'hana@example.test', withAuthenticator: false);
        $this->actingAs($user, 'sanctum');

        $setup = $this->postJson('/api/v1/auth/mfa/enrol')->assertOk()->json();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $setup['secret']);
        $this->assertStringStartsWith('otpauth://totp/', $setup['otpauth_uri']);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.mfa_enabled', false);

        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->wrongCodeFor($user)])->assertStatus(422);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('user.mfa_enabled', false);

        $codes = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->codeFor($user)])->assertOk()->json('recovery_codes');
        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes));
        $this->getJson('/api/v1/auth/me')->assertJsonPath('user.mfa_enabled', true);

        // Nothing readable is stored: the secret is encrypted, the recovery codes are hashes.
        $row = $this->owner()->table('users')->where('id', $user->getKey())->first();
        $this->assertStringNotContainsString($setup['secret'], (string) $row->mfa_secret);
        $stored = $this->owner()->table('user_recovery_codes')->where('user_id', $user->getKey())->pluck('code_hash')->all();
        $this->assertCount(10, $stored);
        $this->assertSame([], array_intersect($stored, $codes));

        // The secret and the codes never appear in an API answer again.
        $me = $this->getJson('/api/v1/auth/me')->getContent();
        $this->assertStringNotContainsString($setup['secret'], $me);
        $this->assertStringNotContainsString('mfa_secret', $me);
        $this->postJson('/api/v1/auth/mfa/enrol')->assertStatus(409);
    }

    public function test_sign_in_without_a_code_fails_for_an_enrolled_user(): void
    {
        $this->createUser('Hana', 'hana@example.test');

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'hana@example.test', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('mfa_required', true)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user');

        // The challenge is not a token.
        $this->withToken($response->json('challenge'))->getJson('/api/v1/companies')->assertUnauthorized();
        $this->assertSame(0, $this->owner()->table('personal_access_tokens')->count());
    }

    public function test_the_code_completes_sign_in_and_cannot_be_used_twice(): void
    {
        $user = $this->createUser('Hana', 'hana@example.test');
        $code = $this->codeFor($user);

        $token = $this->postJson('/api/v1/auth/login/mfa', ['challenge' => $this->challenge(), 'code' => $code])
            ->assertOk()->assertJsonPath('user.email', 'hana@example.test')->json('token');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.mfa_enabled', true);

        // The same code again, on a fresh sign-in: refused, although it is still inside its 30 seconds.
        $this->postJson('/api/v1/auth/login/mfa', ['challenge' => $this->challenge(), 'code' => $code])->assertStatus(422);

        // The next code works.
        $this->travel(Totp::PERIOD)->seconds();
        $this->postJson('/api/v1/auth/login/mfa', ['challenge' => $this->challenge(), 'code' => $this->codeFor($user)])->assertOk();
    }

    public function test_a_challenge_gives_one_sign_in_and_expires(): void
    {
        $user = $this->createUser('Hana', 'hana@example.test');

        $challenge = $this->challenge();
        $this->postJson('/api/v1/auth/login/mfa', ['challenge' => $challenge, 'code' => $this->codeFor($user)])->assertOk();
        $this->travel(Totp::PERIOD)->seconds();
        $this->postJson('/api/v1/auth/login/mfa', ['challenge' => $challenge, 'code' => $this->codeFor($user)])
            ->assertStatus(422)->assertJsonValidationErrors('challenge');

        $this->postJson('/api/v1/auth/login/mfa', ['challenge' => Str::random(64), 'code' => $this->codeFor($user)])
            ->assertStatus(422)->assertJsonValidationErrors('challenge');

        $expired = $this->challenge();
        $this->travel(6)->minutes();
        $this->postJson('/api/v1/auth/login/mfa', ['challenge' => $expired, 'code' => $this->codeFor($user)])
            ->assertStatus(422)->assertJsonValidationErrors('challenge');
    }

    public function test_wrong_codes_are_limited(): void
    {
        $user = $this->createUser('Hana', 'hana@example.test');
        $challenge = $this->challenge();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login/mfa', ['challenge' => $challenge, 'code' => $this->wrongCodeFor($user)])->assertStatus(422);
        }
        // Locked: even the right code is refused now, and so is a recovery-code guess.
        $this->postJson('/api/v1/auth/login/mfa', ['challenge' => $challenge, 'code' => $this->codeFor($user)])->assertStatus(429);
        $this->postJson('/api/v1/auth/login/mfa', ['challenge' => $challenge, 'recovery_code' => 'aaaaa-bbbbb'])->assertStatus(429);

        $this->travel(16)->minutes();
        $this->postJson('/api/v1/auth/login/mfa', ['challenge' => $this->challenge(), 'code' => $this->codeFor($user)])->assertOk();
    }

    public function test_a_recovery_code_signs_in_once(): void
    {
        $user = $this->createUser('Hana', 'hana@example.test', withAuthenticator: false);
        $this->actingAs($user, 'sanctum');
        $this->postJson('/api/v1/auth/mfa/enrol')->assertOk();
        $codes = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->codeFor($user)])->assertOk()->json('recovery_codes');
        $this->app['auth']->forgetGuards();

        // Typed in capitals and without the dash: still the same code.
        $typed = strtoupper(str_replace('-', '', $codes[0]));
        $this->postJson('/api/v1/auth/login/mfa', ['challenge' => $this->challenge(), 'recovery_code' => $typed])->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/v1/auth/login/mfa', ['challenge' => $this->challenge(), 'recovery_code' => $codes[0]])->assertStatus(422);
        $this->postJson('/api/v1/auth/login/mfa', ['challenge' => $this->challenge(), 'recovery_code' => $codes[1]])->assertOk();

        $this->assertSame(2, $this->owner()->table('user_recovery_codes')->where('user_id', $user->getKey())->whereNotNull('used_at')->count());
    }

    public function test_owners_and_admins_must_set_up_an_authenticator_to_work_in_a_company(): void
    {
        $owner = $this->createUser('Hana', 'hana@example.test', withAuthenticator: false);
        $this->actingAs($owner, 'sanctum');
        $company = $this->postJson('/api/v1/companies', ['legal_name' => 'Abyssinia Coffee', 'country_code' => 'ET', 'fiscal_year_start_month' => 7])
            ->assertCreated()->json('id');
        $headers = ['X-Company-Id' => $company];

        $this->getJson('/api/v1/invoices', $headers)->assertForbidden()->assertJsonPath('type', 'MfaEnrolmentRequired');
        $this->getJson('/api/v1/companies')->assertOk(); // choosing a company and setting up the authenticator stay open

        // A bookkeeper is not required to have one.
        $bookkeeper = $this->createUser('Abebe', 'abebe@example.test', withAuthenticator: false);
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $company, 'user_id' => $bookkeeper->getKey(), 'role' => 'bookkeeper']);
        $this->actingAs($bookkeeper, 'sanctum');
        $this->getJson('/api/v1/invoices', $headers)->assertOk();

        // An admin is.
        $this->owner()->table('memberships')->where('user_id', $bookkeeper->getKey())->update(['role' => 'admin']);
        $this->getJson('/api/v1/invoices', $headers)->assertForbidden()->assertJsonPath('type', 'MfaEnrolmentRequired');

        // Once set up, the owner works normally.
        $this->actingAs($owner, 'sanctum');
        $this->postJson('/api/v1/auth/mfa/enrol')->assertOk();
        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->codeFor($owner)])->assertOk();
        $this->getJson('/api/v1/invoices', $headers)->assertOk();
    }

    public function test_changing_or_removing_the_authenticator_needs_the_password_and_a_code(): void
    {
        $user = $this->createUser('Hana', 'hana@example.test', withAuthenticator: false);
        $this->actingAs($user, 'sanctum');
        $this->postJson('/api/v1/auth/mfa/enrol')->assertOk();
        $old = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->codeFor($user)])->assertOk()->json('recovery_codes');

        $this->travel(Totp::PERIOD)->seconds();
        $this->postJson('/api/v1/auth/mfa/recovery-codes', ['password' => 'wrong-password', 'code' => $this->codeFor($user)])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/mfa/recovery-codes', ['password' => self::PASSWORD, 'code' => $this->wrongCodeFor($user)])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->deleteJson('/api/v1/auth/mfa', ['password' => self::PASSWORD])->assertStatus(422);

        $new = $this->postJson('/api/v1/auth/mfa/recovery-codes', ['password' => self::PASSWORD, 'code' => $this->codeFor($user)])
            ->assertOk()->json('recovery_codes');
        $this->assertCount(10, $new);
        $this->assertSame([], array_intersect($old, $new));
        $this->assertSame(10, $this->owner()->table('user_recovery_codes')->where('user_id', $user->getKey())->count());

        $this->travel(Totp::PERIOD)->seconds();
        $this->deleteJson('/api/v1/auth/mfa', ['password' => self::PASSWORD, 'code' => $this->codeFor($user)])->assertNoContent();
        $this->getJson('/api/v1/auth/me')->assertJsonPath('user.mfa_enabled', false);
        $this->assertSame(0, $this->owner()->table('user_recovery_codes')->where('user_id', $user->getKey())->count());
        $this->assertNull($this->owner()->table('users')->where('id', $user->getKey())->value('mfa_secret'));

        // Without one, sign-in is a single step again.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'hana@example.test', 'password' => self::PASSWORD])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_one_user_cannot_read_or_use_another_users_recovery_codes(): void
    {
        $hana = $this->createUser('Hana', 'hana@example.test', withAuthenticator: false);
        $this->actingAs($hana, 'sanctum');
        $this->postJson('/api/v1/auth/mfa/enrol')->assertOk();
        $hanaCodes = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->codeFor($hana)])->assertOk()->json('recovery_codes');

        $this->createUser('Abebe', 'abebe@example.test');
        $this->app['auth']->forgetGuards();
        $abebe = $this->postJson('/api/v1/auth/login', ['email' => 'abebe@example.test', 'password' => self::PASSWORD])->json('challenge');
        $this->postJson('/api/v1/auth/login/mfa', ['challenge' => $abebe, 'recovery_code' => $hanaCodes[0]])->assertStatus(422);

        // Row-level security: inside Abebe's request the database shows none of Hana's codes.
        $abebeId = User::query()->where('email', 'abebe@example.test')->value('id');
        \App\Domain\Tenancy\Support\DatabaseTenantContext::run($abebeId, null, function () use ($hana) {
            $this->assertSame(0, \Illuminate\Support\Facades\DB::table('user_recovery_codes')->where('user_id', $hana->getKey())->count());
        });
    }

    /** Password step for hana@example.test; returns the challenge for the code step. */
    private function challenge(): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => 'hana@example.test', 'password' => self::PASSWORD])
            ->assertOk()->json('challenge');
    }

    /** What the user's authenticator app shows right now. */
    private function codeFor(User $user): string
    {
        return Totp::codeAt($user->fresh()->mfa_secret, Totp::stepAt(now()->getTimestamp()));
    }

    /** A code valid in no step near now: a fixed one like 000000 is right once in a while and made the test flaky. */
    private function wrongCodeFor(User $user): string
    {
        $secret = $user->fresh()->mfa_secret;
        $step = Totp::stepAt(now()->getTimestamp());
        $near = array_map(fn (int $s) => Totp::codeAt($secret, $s), range($step - 2, $step + 2));

        return (string) collect(['000000', '111111', '222222', '333333', '444444', '555555'])->first(fn (string $c) => ! in_array($c, $near, true));
    }
}

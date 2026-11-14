<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordLink;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * SECURITY.md S3: the email address is confirmed before a company can be created; a password reset link is
 * single-use and lasts 60 minutes; a new password ends every session; "sign out everywhere".
 */
final class AccountSecurityTest extends TestCase
{
    use RefreshLedgerDatabase;

    private const COMPANY = ['legal_name' => 'Abyssinia Coffee', 'country_code' => 'ET', 'fiscal_year_start_month' => 7];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_a_company_cannot_be_created_until_the_email_address_is_confirmed(): void
    {
        $token = $this->postJson('/api/v1/auth/register', ['name' => 'Hana', 'email' => 'Hana@Example.test', 'password' => 'a-long-password-1'])
            ->assertCreated()->assertJsonPath('user.email_verified', false)->json('token');
        $user = User::query()->where('email', 'hana@example.test')->firstOrFail();
        $link = $this->sentToken($user, VerifyEmailAddress::class);

        $this->as($token)->postJson('/api/v1/companies', self::COMPANY)->assertForbidden()->assertJsonPath('type', 'EmailNotVerified');
        $this->assertSame(0, $this->owner()->table('companies')->count());

        // Only a hash of the link's token is stored.
        $this->assertSame([hash('sha256', $link)], $this->owner()->table('user_email_tokens')->pluck('token_hash')->all());

        $this->postJson('/api/v1/auth/email/verify', ['token' => Str::random(64)])->assertStatus(422);
        $this->postJson('/api/v1/auth/email/verify', ['token' => $link])->assertOk();
        $this->postJson('/api/v1/auth/email/verify', ['token' => $link])->assertStatus(422); // once

        $this->as($token)->getJson('/api/v1/auth/me')->assertJsonPath('user.email_verified', true);
        $this->as($token)->postJson('/api/v1/companies', self::COMPANY)->assertCreated();
    }

    public function test_a_confirmation_link_expires_and_a_new_one_replaces_it(): void
    {
        $user = $this->createUser('Hana', 'hana@example.test', verified: false);
        $token = $user->issueToken();

        $this->as($token)->postJson('/api/v1/auth/email/resend')->assertStatus(202);
        $first = $this->sentToken($user, VerifyEmailAddress::class);
        $this->as($token)->postJson('/api/v1/auth/email/resend')->assertStatus(202);
        $second = $this->sentToken($user, VerifyEmailAddress::class, nth: 2);
        $this->assertNotSame($first, $second);

        $this->postJson('/api/v1/auth/email/verify', ['token' => $first])->assertStatus(422); // replaced

        $this->travel(25)->hours();
        $this->postJson('/api/v1/auth/email/verify', ['token' => $second])->assertStatus(422); // expired
        $this->as($token = $user->issueToken())->getJson('/api/v1/auth/me')->assertJsonPath('user.email_verified', false);

        // Asking again is limited, so the mailbox cannot be flooded.
        $this->as($token)->postJson('/api/v1/auth/email/resend')->assertStatus(202);
        $this->as($token)->postJson('/api/v1/auth/email/resend')->assertStatus(202);
        $this->as($token)->postJson('/api/v1/auth/email/resend')->assertStatus(202);
        $this->as($token)->postJson('/api/v1/auth/email/resend')->assertStatus(429);
    }

    public function test_forgot_password_answers_the_same_for_any_address_and_emails_only_real_accounts(): void
    {
        $user = $this->createUser('Hana', 'hana@example.test');

        $known = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'HANA@example.test'])->assertOk()->json();
        $unknown = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.test'])->assertOk()->json();
        $this->assertSame($known, $unknown);

        Notification::assertSentToTimes($user, ResetPasswordLink::class, 1);
        Notification::assertCount(1);

        // Three emails an hour per address; the answer does not change.
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'hana@example.test'])->assertOk();
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'hana@example.test'])->assertOk();
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'hana@example.test'])->assertOk()->assertExactJson($known);
        Notification::assertSentToTimes($user, ResetPasswordLink::class, 3);
    }

    public function test_a_reset_link_works_once_for_sixty_minutes_and_ends_every_session(): void
    {
        $user = $this->createUser('Hana', 'hana@example.test', withAuthenticator: false);
        $phone = $user->issueToken();
        $laptop = $user->issueToken();

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'hana@example.test'])->assertOk();
        $link = $this->sentToken($user, ResetPasswordLink::class);
        $this->assertNotContains($link, $this->owner()->table('user_email_tokens')->pluck('token_hash')->all());

        // A weak password is refused and does not use the link up.
        $this->postJson('/api/v1/auth/password/reset', ['token' => $link, 'password' => 'short'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/password/reset', ['token' => Str::random(64), 'password' => 'a-new-long-password'])->assertStatus(422)->assertJsonValidationErrors('token');

        $this->postJson('/api/v1/auth/password/reset', ['token' => $link, 'password' => 'a-new-long-password'])->assertOk();
        $this->postJson('/api/v1/auth/password/reset', ['token' => $link, 'password' => 'another-long-password'])->assertStatus(422); // once

        // Every session is over; the old password is gone; the new one works.
        $this->assertSame(0, $this->owner()->table('personal_access_tokens')->count());
        $this->as($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->as($laptop)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => 'hana@example.test', 'password' => 'secret-password'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['email' => 'hana@example.test', 'password' => 'a-new-long-password'])->assertOk()->assertJsonStructure(['token']);

        // Expiry: a link older than 60 minutes is dead.
        $this->travel(61)->minutes(); // also past the three-an-hour limit
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'hana@example.test'])->assertOk();
        $late = $this->sentToken($user, ResetPasswordLink::class, nth: 2);
        $this->travel(61)->minutes();
        $this->postJson('/api/v1/auth/password/reset', ['token' => $late, 'password' => 'yet-another-password'])->assertStatus(422)->assertJsonValidationErrors('token');
    }

    public function test_changing_the_password_ends_every_other_session(): void
    {
        $user = $this->createUser('Hana', 'hana@example.test');
        $phone = $user->issueToken();
        $laptop = $user->issueToken();

        $this->as($laptop)->postJson('/api/v1/auth/password', ['current_password' => 'wrong-password', 'password' => 'a-new-long-password'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->as($laptop)->postJson('/api/v1/auth/password', ['current_password' => 'secret-password', 'password' => 'secret-password'])
            ->assertStatus(422)->assertJsonValidationErrors('password'); // must be different
        $this->as($laptop)->postJson('/api/v1/auth/password', ['current_password' => 'secret-password', 'password' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertSame(2, $this->owner()->table('personal_access_tokens')->count());

        $fresh = $this->as($laptop)->postJson('/api/v1/auth/password', ['current_password' => 'secret-password', 'password' => 'a-new-long-password'])
            ->assertOk()->json('token');

        $this->assertSame(1, $this->owner()->table('personal_access_tokens')->count());
        $this->as($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->as($laptop)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->as($fresh)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_sign_out_everywhere_ends_every_session(): void
    {
        $user = $this->createUser('Hana', 'hana@example.test');
        $other = $this->createUser('Abebe', 'abebe@example.test');
        $phone = $user->issueToken();
        $laptop = $user->issueToken();
        $abebe = $other->issueToken();

        $this->as($laptop)->postJson('/api/v1/auth/logout-everywhere')->assertNoContent();

        $this->as($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->as($laptop)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->as($abebe)->getJson('/api/v1/auth/me')->assertOk(); // nobody else is affected
    }

    /** The next request is made with this token (and not as whoever made the previous one). */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** The token inside the nth email of this kind sent to the user. */
    private function sentToken(User $user, string $notification, int $nth = 1): string
    {
        $sent = Notification::sent($user, $notification);
        $this->assertGreaterThanOrEqual($nth, $sent->count(), "expected at least {$nth} {$notification} to {$user->email}");

        return $sent->values()[$nth - 1]->token;
    }
}

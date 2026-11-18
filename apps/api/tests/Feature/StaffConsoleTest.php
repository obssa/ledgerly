<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Totp;
use App\Notifications\StaffSetupLink;
use Illuminate\Support\Facades\Notification;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * SECURITY.md S8: the staff console (066). Closed unless the address is allow-listed; an account made by the operator
 * is set up through its emailed link with a strong password and an authenticator; signing in takes both, a code once;
 * the company list and a support session need the staff token (a customer's means nothing); a session needs a reason,
 * shows one company's books read-only, and ends; every step is in the staff log; a switched-off account stops at once.
 */
final class StaffConsoleTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_staff_sign_in_with_a_code_and_read_one_company_in_a_session_with_a_reason(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $customerToken = \App\Models\User::query()->findOrFail($c['user'])->createToken('web')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        // Closed by default, and to any address not on the list.
        config(['staff.allowed_ips' => []]);
        $this->postJson('/api/v1/staff/login', ['email' => 'x@example.test', 'password' => 'x', 'code' => '000000'])->assertNotFound();
        config(['staff.allowed_ips' => ['127.0.0.1']]);

        // The operator makes the account; its setup link comes by email.
        Notification::fake();
        $this->artisan('ledgerly:staff', ['email' => 'Support@Ledgerly.example', '--name' => 'Hana Support'])->assertSuccessful();
        $setup = null;
        Notification::assertSentOnDemand(StaffSetupLink::class, function (StaffSetupLink $n) use (&$setup) {
            $setup = $n->token;

            return true;
        });
        $form = $this->getJson("/api/v1/staff/setup?token={$setup}")->assertOk()->assertJsonPath('email', 'support@ledgerly.example')->json();
        $code = fn () => Totp::codeAt($form['secret'], Totp::stepAt(now()->getTimestamp()));
        $this->postJson('/api/v1/staff/setup', ['token' => $setup, 'password' => 'short', 'secret' => $form['secret'], 'code' => $code()])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $password = 'Correct-Horse-Battery-9';
        $this->postJson('/api/v1/staff/setup', ['token' => $setup, 'password' => $password, 'secret' => $form['secret'], 'code' => $code()])->assertOk();
        $this->postJson('/api/v1/staff/setup', ['token' => $setup, 'password' => $password, 'secret' => $form['secret'], 'code' => $code()])->assertUnprocessable();
        $this->assertStringStartsWith('$2y$', (string) $this->owner()->table('staff_users')->where('email', 'support@ledgerly.example')->value('password'));
        $this->assertNotSame($form['secret'], (string) $this->owner()->table('staff_users')->where('email', 'support@ledgerly.example')->value('mfa_secret'));

        // Signing in takes the password and a code, the code once (it was used by the setup in this step).
        $this->postJson('/api/v1/staff/login', ['email' => 'support@ledgerly.example', 'password' => 'wrong-password', 'code' => $code()])->assertUnprocessable();
        $this->postJson('/api/v1/staff/login', ['email' => 'support@ledgerly.example', 'password' => $password, 'code' => $code()])->assertUnprocessable();
        $this->travel(31)->seconds();
        $token = $this->postJson('/api/v1/staff/login', ['email' => 'support@ledgerly.example', 'password' => $password, 'code' => $code()])->assertOk()->json('token');
        $staff = ['Authorization' => "Bearer {$token}"];

        // A customer's token is nothing here, and the staff token is nothing in the customers' API.
        $this->getJson('/api/v1/staff/companies', ['Authorization' => "Bearer {$customerToken}"])->assertUnauthorized();
        $this->assertNull(\Laravel\Sanctum\PersonalAccessToken::findToken($token), 'a staff token is no customer token');

        $this->assertContains($c['company'], array_column($this->getJson('/api/v1/staff/companies?search=Abyssinia', $staff)->assertOk()->json('data'), 'id'));
        $this->postJson('/api/v1/staff/sessions', ['company_id' => $c['company'], 'reason' => 'Help'], $staff)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $session = $this->postJson('/api/v1/staff/sessions', ['company_id' => $c['company'], 'reason' => 'Owner asked why the trial balance looks wrong', 'minutes' => 30], $staff)
            ->assertCreated()->json('id');
        $view = $this->getJson("/api/v1/staff/sessions/{$session}", $staff)->assertOk()->assertJsonPath('company.legal_name', 'Abyssinia Coffee')->json();
        $this->assertNotEmpty($view['trial_balance']);
        $this->assertNotEmpty($view['audit']);
        $debits = array_sum(array_map(fn ($r) => (float) $r['debit'], $view['trial_balance']));
        $credits = array_sum(array_map(fn ($r) => (float) $r['credit'], $view['trial_balance']));
        $this->assertEqualsWithDelta($debits, $credits, 0.001, 'the trial balance seen balances');

        $this->postJson("/api/v1/staff/sessions/{$session}/end", [], $staff)->assertOk();
        $this->getJson("/api/v1/staff/sessions/{$session}", $staff)->assertNotFound();
        $this->assertSame(['account_created', 'account_set_up', 'sign_in_failed', 'sign_in_failed', 'signed_in', 'companies_listed', 'support_session_started', 'company_viewed', 'support_session_ended'],
            $this->owner()->table('staff_log')->orderBy('id')->pluck('action')->all());
        $this->assertSame('Owner asked why the trial balance looks wrong', $this->owner()->table('staff_sessions')->where('id', $session)->value('reason'));

        // Switched off by the operator: the token stops at once.
        $this->artisan('ledgerly:staff', ['email' => 'support@ledgerly.example', '--deactivate' => true])->assertSuccessful();
        $this->getJson('/api/v1/staff/me', $staff)->assertUnauthorized();
    }
}

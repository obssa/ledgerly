<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Models\User;
use App\Support\FiscalCalendar;
use DateTimeImmutable;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sets up the demo company and one shared account per role (`php artisan ledgerly:demo-setup`, run by the deploy
 * as the owner role when DEMO_LOGINS=true). Safe to run on every deploy: it adds only what is missing.
 *
 * Only the accounts and the demo list are written with the owner role. Everything in the company (settings,
 * customers, invoices, payments) goes through the API as the demo owner, so every rule a real user meets applies.
 *
 * A demo account is always a new user with a random password nobody knows. It never takes over an existing
 * account with the same email, so registering a demo address first cannot make someone a demo account holder.
 */
final class DemoSetup
{
    /** Who signs in for each role: shown in the user menu. */
    private const NAMES = [
        'owner' => 'Selam Tesfaye', 'admin' => 'Bereket Assefa', 'accountant' => 'Dawit Bekele', 'bookkeeper' => 'Meron Alemu',
        'sales' => 'Yonas Girma', 'purchasing' => 'Liya Mekonnen', 'approver' => 'Hirut Kebede', 'viewer' => 'Abel Haile',
    ];

    private string $token = '';

    private string $company = '';

    public function __construct(private readonly HttpKernel $kernel)
    {
    }

    /** @param callable(string): void $say */
    public function run(callable $say): void
    {
        $owner = DB::connection('owner');
        $roles = array_keys((array) config('demo.roles'));
        if (! in_array('owner', $roles, true)) {
            throw new RuntimeException('config/demo.php must offer the owner role: it creates the demo company.');
        }

        $company = $owner->table('demo_accounts')->value('company_id');
        if ($company === null) {
            $company = $this->createCompany($owner);
            $say("Created the demo company {$company}.");
        }

        foreach ($roles as $role) {
            if ($owner->table('demo_accounts')->where('role', $role)->exists()) {
                continue;
            }
            $user = $this->newUser($owner, $role);
            $owner->table('memberships')->insert(['company_id' => $company, 'user_id' => $user, 'role' => $role, 'status' => 'active']);
            $owner->table('demo_accounts')->insert(['user_id' => $user, 'company_id' => $company, 'role' => $role]);
            $say("Added the demo {$role}.");
        }
        $say('Demo sign-in is ready: ' . implode(', ', $roles) . '.');
    }

    private function newUser(ConnectionInterface $owner, string $role): string
    {
        $id = (string) Str::uuid();
        $owner->table('users')->insert([
            'id' => $id,
            'name' => self::NAMES[$role] ?? ucfirst($role),
            'email' => "{$role}@demo.ledgerly.invalid", // .invalid never delivers (RFC 2606)
            'password' => Hash::make(Str::random(64)),
            'email_verified_at' => now(),
        ]);

        return $id;
    }

    /** The owner, the company and its sample books. */
    private function createCompany(ConnectionInterface $owner): string
    {
        $userId = $this->newUser($owner, 'owner');
        $user = User::query()->findOrFail($userId);
        $this->token = $user->createToken('demo-setup', ['*'], now()->addMinutes(30))->plainTextToken;

        try {
            $today = new DateTimeImmutable('today');
            $fiscalStart = (new FiscalCalendar('gregorian', 7))->yearContaining($today)['start'];
            $this->company = $this->call('POST', 'companies', [
                'legal_name' => config('demo.company'), 'trading_name' => 'Abyssinia Coffee', 'legal_form' => 'private_limited',
                'country_code' => 'ET', 'base_currency' => 'ETB', 'calendar' => 'gregorian', 'fiscal_year_start_month' => 7,
                'conversion_date' => $fiscalStart->format('Y-m-d'), 'vat_registered' => true, 'tin' => '0045678912',
                'address_line1' => 'Bole Road, Woreda 03', 'city' => 'Addis Ababa', 'phone' => '+251 11 661 0000',
            ], company: false)['id'];

            // On the list before the books go in: the list is what lets a shared owner work without an authenticator.
            $owner->table('demo_accounts')->insert(['user_id' => $userId, 'company_id' => $this->company, 'role' => 'owner']);
            try {
                $this->books($today, $fiscalStart);
            } catch (\Throwable $e) {
                // Half-filled books are not offered: the next run starts a new demo company.
                $owner->table('demo_accounts')->where('user_id', $userId)->delete();
                throw $e;
            }
        } finally {
            $user->tokens()->delete();
        }

        return $this->company;
    }

    /** Sample books inside the current fiscal year: opening balances, customers, invoices in every state, payments. */
    private function books(DateTimeImmutable $today, DateTimeImmutable $fiscalStart): void
    {
        $from = max($fiscalStart, $today->modify('-85 days'));
        $span = (int) $from->diff($today)->days;
        $day = fn (float $f): string => $from->modify('+' . (int) floor($f * $span) . ' days')->format('Y-m-d');

        $accounts = collect($this->call('GET', 'accounts')['data']);
        $account = fn (string $code): string => $accounts->firstWhere('code', $code)['id'];
        $tax = collect($this->call('GET', 'tax-rates')['data']);
        $vat = $tax->firstWhere('code', 'VAT15')['id'];
        $zero = $tax->firstWhere('code', 'VAT0')['id'];

        $this->call('PUT', 'opening-balances', ['lines' => [
            ['account_id' => $account('1100'), 'debit' => '420000.00'],
            ['account_id' => $account('1000'), 'debit' => '15000.00'],
            ['account_id' => $account('3000'), 'credit' => '435000.00'],
        ]]);

        $hq = $this->call('POST', 'dimensions', ['kind' => 'location', 'code' => 'ADD', 'name' => 'Addis Ababa head office'])['id'];
        $this->call('POST', 'dimensions', ['kind' => 'location', 'code' => 'HWS', 'name' => 'Hawassa branch']);
        $this->call('POST', 'dimensions', ['kind' => 'project', 'code' => 'EXP', 'name' => 'Export season']);

        $this->call('PUT', 'currencies', ['currency' => 'USD', 'enabled' => true]);
        $this->call('POST', 'exchange-rates', ['currency' => 'USD', 'rate_date' => $day(0.6), 'rate' => '128.450000']);

        $customer = fn (array $c): string => $this->call('POST', 'customers', $c)['id'];
        $hotel = $customer(['name' => 'Sheger Grand Hotel', 'tax_id' => '0012345678', 'email' => 'accounts@sheger-hotel.example', 'payment_terms_days' => 30, 'credit_limit' => '120000.00']);
        $cafe = $customer(['name' => 'Tomoca Café Bole', 'payment_terms_days' => 15]);
        $retail = $customer(['name' => 'Ethio Retail Supermarkets', 'tax_id' => '0023456789', 'payment_terms_days' => 30, 'credit_limit' => '40000.00']);
        $venue = $customer(['name' => 'Addis Conference Centre', 'payment_terms_days' => 30]);
        $export = $customer(['name' => 'Hamburg Kaffee Import GmbH', 'currency' => 'USD', 'payment_terms_days' => 45]);

        $line = fn (string $what, string $qty, string $price, string $acc, string $rate) => [
            'description' => $what, 'quantity' => $qty, 'unit_price' => $price, 'account_id' => $account($acc), 'tax_rate_id' => $rate,
        ];
        $invoice = function (string $contact, float $f, int $terms, array $lines, string $currency = 'ETB', bool $post = true) use ($day): string {
            $issued = $day($f);
            $id = $this->call('POST', 'invoices', ['contact_id' => $contact, 'issue_date' => $issued, 'currency' => $currency,
                'due_date' => (new DateTimeImmutable($issued))->modify("+{$terms} days")->format('Y-m-d'), 'lines' => $lines])['id'];
            if ($post) {
                $this->call('POST', "invoices/{$id}/approve");
            }

            return $id;
        };
        $pay = fn (string $contact, float $f, string $method, string $amount, string $inv) => $this->call('POST', 'payments-received', [
            'contact_id' => $contact, 'account_id' => $account('1100'), 'payment_date' => $day($f), 'method' => $method,
            'currency' => 'ETB', 'amount' => $amount, 'allocations' => [['invoice_id' => $inv, 'amount' => $amount]],
        ]);

        $i1 = $invoice($hotel, 0.05, 30, [$line('Roasted coffee, Yirgacheffe 1 kg', '40', '1150', '4000', $vat)]);          // 52,900.00
        $pay($hotel, 0.30, 'bank_transfer', '52900.00', $i1);
        $i2 = $invoice($cafe, 0.15, 15, [$line('Green coffee, Sidama grade 2 (kg)', '25', '780', '4000', $vat)]);          // 22,425.00
        $pay($cafe, 0.40, 'mobile_money', '10000.00', $i2);
        $invoice($retail, 0.25, 30, [$line('Ground coffee 500 g', '120', '320', '4000', $vat)]);                           // 44,160.00, overdue
        $i4 = $invoice($venue, 0.45, 30, [$line('Coffee ceremony catering (per event)', '3', '6500', '4100', $vat)]);    // 22,425.00
        $credit = $this->call('POST', "invoices/{$i4}/credit-notes", ['issue_date' => $day(0.55), 'notes' => 'One event cancelled',
            'lines' => [$line('Coffee ceremony catering, cancelled event', '1', '6500', '4100', $vat)]])['id'];
        $this->call('POST', "invoices/{$credit}/approve");
        $invoice($export, 0.6, 45, [$line('Green coffee export, Guji natural (kg)', '600', '6.40', '4000', $zero)], 'USD'); // 3,840.00 USD
        $invoice($hotel, 0.8, 30, [$line('Roasted coffee, Yirgacheffe 1 kg', '30', '1150', '4000', $vat)]);                // 39,675.00
        $invoice($cafe, 0.95, 15, [$line('Green coffee, Sidama grade 2 (kg)', '40', '780', '4000', $vat)], post: false);   // draft

        $this->call('POST', 'journal-entries', ['date' => $day(0.5), 'memo' => 'Warehouse rent', 'lines' => [
            ['account_id' => $account('6100'), 'debit' => '25000.00', 'location_id' => $hq],
            ['account_id' => $account('1100'), 'credit' => '25000.00'],
        ]]);
    }

    /** One API request as the demo owner, through the full middleware stack. Anything but success stops the setup. */
    private function call(string $method, string $uri, array $body = [], bool $company = true): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token, 'REMOTE_ADDR' => '127.0.0.1'];
        if ($company) {
            $server['HTTP_X_COMPANY_ID'] = $this->company;
        }
        $request = Request::create('/api/v1/' . $uri, $method, [], [], [], $server, $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR));
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
        app('auth')->forgetGuards();

        if ($response->getStatusCode() >= 300) {
            throw new RuntimeException("Demo setup: {$method} {$uri} answered {$response->getStatusCode()}: " . Str::limit((string) $response->getContent(), 400));
        }

        return (array) json_decode((string) $response->getContent(), true);
    }
}

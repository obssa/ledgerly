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

        $this->addPurchases($owner, $company, $say);
        $this->addPaymentsMade($owner, $company, $say);
        $this->addExpenses($owner, $company, $say);
        $this->addSupplierCredit($owner, $company, $say);
        $this->addInventory($owner, $company, $say);
        $this->addStatement($owner, $company, $say);

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

    /** A date inside the sample window (the last 85 days, within the current fiscal year): 0 is its start, 1 today. */
    private function dayIn(DateTimeImmutable $today, DateTimeImmutable $fiscalStart): \Closure
    {
        $from = max($fiscalStart, $today->modify('-85 days'));
        $span = (int) $from->diff($today)->days;

        return fn (float $f): string => $from->modify('+' . (int) floor($f * $span) . ' days')->format('Y-m-d');
    }

    /**
     * Module 3 sample: suppliers and bills. Added to a demo company that has no supplier yet, so a demo set up before
     * suppliers existed gets them on the next deploy.
     */
    private function addPurchases(ConnectionInterface $owner, string $company, callable $say): void
    {
        if ($owner->table('contacts')->where(['company_id' => $company, 'is_vendor' => true])->exists()) {
            return;
        }
        $user = User::query()->findOrFail($owner->table('demo_accounts')->where('role', 'owner')->value('user_id'));
        $this->token = $user->createToken('demo-setup', ['*'], now()->addMinutes(30))->plainTextToken;
        $this->company = $company;

        try {
            $today = new DateTimeImmutable('today');
            $day = $this->dayIn($today, (new FiscalCalendar('gregorian', 7))->yearContaining($today)['start']);
            $accounts = collect($this->call('GET', 'accounts')['data']);
            $account = fn (string $code): string => $accounts->firstWhere('code', $code)['id'];
            $vat = collect($this->call('GET', 'tax-rates')['data'])->firstWhere('code', 'VAT15')['id'];

            $supplier = fn (array $s): string => $this->call('POST', 'suppliers', $s)['id'];
            $telecom = $supplier(['name' => 'Ethio Telecom', 'tax_id' => '0000030603', 'payment_terms_days' => 15, 'default_expense_account_id' => $account('6300'),
                'payout' => ['bank_name' => 'Commercial Bank of Ethiopia', 'account_name' => 'Ethio Telecom', 'account_number' => '1000004567891']]);
            $farmers = $supplier(['name' => 'Yirgacheffe Coffee Farmers Cooperative Union', 'tax_id' => '0011223344', 'payment_terms_days' => 30,
                'default_expense_account_id' => $account('5000'), 'payout' => ['bank_name' => 'Cooperative Bank of Oromia', 'account_number' => '1023400098765'],
                'withholding_exempt' => true, 'withholding_exempt_reason' => 'Cooperative exemption certificate WHT-EX-2026-031']);
            $packaging = $supplier(['name' => 'Addis Packaging PLC', 'payment_terms_days' => 30, 'default_expense_account_id' => $account('6800'),
                'payout' => ['mobile_money_provider' => 'telebirr', 'mobile_money_number' => '+251 911 234 567']]);

            $bill = function (string $contact, float $f, int $terms, string $ref, array $lines, bool $post = true, bool $submit = false) use ($day): void {
                $issued = $day($f);
                $id = $this->call('POST', 'bills', ['contact_id' => $contact, 'issue_date' => $issued, 'reference' => $ref, 'currency' => 'ETB',
                    'due_date' => (new DateTimeImmutable($issued))->modify("+{$terms} days")->format('Y-m-d'), 'lines' => $lines])['id'];
                if ($submit) {
                    $this->call('POST', "bills/{$id}/submit");
                } elseif ($post) {
                    $this->call('POST', "bills/{$id}/approve");
                }
            };
            $line = fn (string $what, string $qty, string $price, string $acc, ?string $tax) => [
                'description' => $what, 'quantity' => $qty, 'unit_price' => $price, 'account_id' => $account($acc), 'tax_rate_id' => $tax,
            ];

            $bill($farmers, 0.1, 30, 'YCFCU-2291', [$line('Green coffee, Yirgacheffe grade 1 (kg)', '400', '450', '5000', null)]);   // 180,000.00, overdue
            $bill($telecom, 0.35, 15, 'ET-8823140', [$line('Internet and phone lines', '1', '3200', '6300', $vat)]);                   // 3,680.00, overdue
            $bill($telecom, 0.7, 15, 'ET-8869902', [$line('Internet and phone lines', '1', '3200', '6300', $vat)]);                    // 3,680.00
            $bill($packaging, 0.9, 30, 'AP-0457', [$line('Coffee bags, 1 kg, printed', '2000', '12', '6800', $vat)], submit: true);   // awaiting approval
        } finally {
            $user->tokens()->where('name', 'demo-setup')->delete();
        }
        $say('Added the demo suppliers and bills.');
    }

    /** Module 3 sample: two bills paid, one in full and one in part. Added once, to a demo company with no payment made yet. */
    private function addPaymentsMade(ConnectionInterface $owner, string $company, callable $say): void
    {
        if ($owner->table('payments')->where(['company_id' => $company, 'direction' => 'out'])->exists()) {
            return;
        }
        $bills = $owner->table('documents')->where(['company_id' => $company, 'type' => 'bill', 'status' => 'posted'])
            ->whereIn('reference', ['ET-8823140', 'YCFCU-2291'])->get(['id', 'contact_id', 'reference', 'issue_date', 'amount_due'])->keyBy('reference');
        if ($bills->count() < 2) {
            return; // the sample bills were changed by visitors: leave the demo as it is
        }
        $user = User::query()->findOrFail($owner->table('demo_accounts')->where('role', 'owner')->value('user_id'));
        $this->token = $user->createToken('demo-setup', ['*'], now()->addMinutes(30))->plainTextToken;
        $this->company = $company;

        try {
            $bank = collect($this->call('GET', 'accounts')['data'])->firstWhere('code', '1100')['id'];
            $pay = fn (object $bill, string $amount, string $ref) => $this->call('POST', 'payments-made', [
                'contact_id' => $bill->contact_id, 'account_id' => $bank, 'method' => 'bank_transfer', 'currency' => 'ETB', 'reference' => $ref,
                'payment_date' => min(now()->format('Y-m-d'), (new DateTimeImmutable((string) $bill->issue_date))->modify('+5 days')->format('Y-m-d')),
                'amount' => $amount, 'allocations' => [['bill_id' => $bill->id, 'amount' => $amount]],
            ]);
            $pay($bills['ET-8823140'], (string) $bills['ET-8823140']->amount_due, 'TT-310045');   // in full
            $pay($bills['YCFCU-2291'], '120000.00', 'TT-310046');                                  // in part; the union is withholding-exempt
        } finally {
            $user->tokens()->where('name', 'demo-setup')->delete();
        }
        $say('Added the demo payments made.');
    }

    /** Module 3 sample: a supplier credit for a day of internet outage on Ethio Telecom's open bill. Added once. */
    private function addSupplierCredit(ConnectionInterface $owner, string $company, callable $say): void
    {
        if ($owner->table('documents')->where(['company_id' => $company, 'type' => 'vendor_credit'])->exists()) {
            return;
        }
        $bill = $owner->table('documents')->where(['company_id' => $company, 'type' => 'bill', 'reference' => 'ET-8869902'])
            ->whereIn('status', ['posted', 'part_paid'])->first(['id', 'issue_date']);
        if ($bill === null) {
            return; // the sample bill was paid or changed by visitors
        }
        $user = User::query()->findOrFail($owner->table('demo_accounts')->where('role', 'owner')->value('user_id'));
        $this->token = $user->createToken('demo-setup', ['*'], now()->addMinutes(30))->plainTextToken;
        $this->company = $company;

        try {
            $lines = $this->call('GET', "bills/{$bill->id}")['lines'];
            $credit = $this->call('POST', "bills/{$bill->id}/credits", [
                'issue_date' => min(now()->format('Y-m-d'), (new DateTimeImmutable((string) $bill->issue_date))->modify('+3 days')->format('Y-m-d')),
                'reference' => 'ET-CR-55120', 'notes' => 'Two days of internet outage credited',
                'lines' => [['description' => 'Outage credit, 2 days', 'quantity' => '1', 'unit_price' => '213.33',
                    'account_id' => $lines[0]['account_id'], 'tax_rate_id' => $lines[0]['tax_rate_id']]],
            ])['id'];
            $this->call('POST', "bills/{$credit}/approve");
        } finally {
            $user->tokens()->where('name', 'demo-setup')->delete();
        }
        $say('Added the demo supplier credit.');
    }

    /** Module 4 sample: two warehouses, three stock items with opening stock at the conversion date, and a service. Added once. */
    private function addInventory(ConnectionInterface $owner, string $company, callable $say): void
    {
        if ($owner->table('items')->where(['company_id' => $company, 'type' => 'inventory'])->exists()) {
            return;
        }
        $user = User::query()->findOrFail($owner->table('demo_accounts')->where('role', 'owner')->value('user_id'));
        $this->token = $user->createToken('demo-setup', ['*'], now()->addMinutes(30))->plainTextToken;
        $this->company = $company;

        try {
            $opening = (string) ($owner->table('companies')->where('id', $company)->value('conversion_date') ?? now()->format('Y-m-d'));
            $accounts = collect($this->call('GET', 'accounts')['data']);
            $roastery = $this->call('POST', 'warehouses', ['name' => 'Bole roastery'])['id'];
            $shop = $this->call('POST', 'warehouses', ['name' => 'Hawassa shop'])['id'];
            $item = fn (array $i) => $this->call('POST', 'items', $i)['id'];
            $roasted = $item(['name' => 'Roasted coffee, Yirgacheffe 1 kg', 'sku' => 'RC-YRG-1K', 'type' => 'inventory', 'unit' => 'bag', 'sale_price' => '1150', 'reorder_point' => '40',
                'income_account_id' => $accounts->firstWhere('code', '4000')['id']]);
            $green = $item(['name' => 'Green coffee, Sidama grade 2', 'sku' => 'GC-SID-2', 'type' => 'inventory', 'unit' => 'kg', 'sale_price' => '780', 'purchase_price' => '450']);
            $bags = $item(['name' => 'Coffee bags, 1 kg, printed', 'sku' => 'PK-BAG-1K', 'type' => 'inventory', 'unit' => 'bag', 'purchase_price' => '12', 'reorder_point' => '500']);
            $item(['name' => 'Coffee ceremony catering (per event)', 'sku' => 'SV-CER', 'type' => 'service', 'sale_price' => '6500', 'income_account_id' => $accounts->firstWhere('code', '4100')['id']]);

            $stock = fn (string $it, string $wh, string $qty, string $cost) => $this->call('POST', 'stock/adjustments', [
                'item_id' => $it, 'warehouse_id' => $wh, 'date' => $opening, 'quantity' => $qty, 'unit_cost' => $cost, 'opening' => true, 'reason' => 'Opening count',
            ]);
            $stock($roasted, $roastery, '180', '640');
            $stock($roasted, $shop, '35', '640');
            $stock($green, $roastery, '900', '450');
            $stock($bags, $roastery, '1200', '12');
        } finally {
            $user->tokens()->where('name', 'demo-setup')->delete();
        }
        $say('Added the demo items and opening stock.');
    }

    /** Module 3 sample: expenses paid on the spot, from cash and from the bank. Added once. */
    private function addExpenses(ConnectionInterface $owner, string $company, callable $say): void
    {
        if ($owner->table('documents')->where(['company_id' => $company, 'type' => 'expense'])->exists()) {
            return;
        }
        $user = User::query()->findOrFail($owner->table('demo_accounts')->where('role', 'owner')->value('user_id'));
        $this->token = $user->createToken('demo-setup', ['*'], now()->addMinutes(30))->plainTextToken;
        $this->company = $company;

        try {
            $today = new DateTimeImmutable('today');
            $day = $this->dayIn($today, (new FiscalCalendar('gregorian', 7))->yearContaining($today)['start']);
            $accounts = collect($this->call('GET', 'accounts')['data']);
            $account = fn (string $code): string => $accounts->firstWhere('code', $code)['id'];
            $vat = collect($this->call('GET', 'tax-rates')['data'])->firstWhere('code', 'VAT15')['id'];
            $fuel = $this->call('POST', 'suppliers', ['name' => 'TotalEnergies Bole', 'default_expense_account_id' => $account('6400')])['id'];
            $power = $this->call('POST', 'suppliers', ['name' => 'Ethiopian Electric Utility', 'tax_id' => '0000011223', 'default_expense_account_id' => $account('6200')])['id'];

            $expense = fn (string $contact, float $f, string $from, string $method, string $ref, string $what, string $price, string $acc) => $this->call('POST', 'expenses', [
                'contact_id' => $contact, 'paid_from_account_id' => $account($from), 'payment_method' => $method, 'reference' => $ref,
                'issue_date' => $day($f), 'currency' => 'ETB',
                'lines' => [['description' => $what, 'quantity' => '1', 'unit_price' => $price, 'account_id' => $account($acc), 'tax_rate_id' => $vat]],
            ]);
            $expense($fuel, 0.5, '1000', 'cash', 'TE-44871', 'Diesel for the delivery van', '4200', '6400');
            $expense($power, 0.85, '1100', 'mobile_money', 'EEU-2027-118834', 'Electricity, roastery', '6800', '6200');
        } finally {
            $user->tokens()->where('name', 'demo-setup')->delete();
        }
        $say('Added the demo expenses.');
    }

    /**
     * Module 5 sample: the bank's statement for the sample window. It carries the bank side of what is already in the
     * books (to match), and the charges and interest only the bank knew about (to categorise). One charge is categorised
     * already, so every tab has a line. Imported once.
     */
    private function addStatement(ConnectionInterface $owner, string $company, callable $say): void
    {
        if ($owner->table('bank_transactions')->where('company_id', $company)->exists()) {
            return;
        }
        $user = User::query()->findOrFail($owner->table('demo_accounts')->where('role', 'owner')->value('user_id'));
        $this->token = $user->createToken('demo-setup', ['*'], now()->addMinutes(30))->plainTextToken;
        $this->company = $company;

        try {
            $today = new DateTimeImmutable('today');
            $accounts = collect($this->call('GET', 'accounts')['data']);
            $bank = $accounts->firstWhere('code', '1100')['id'] ?? null;
            if ($bank === null) {
                return;
            }
            $register = $this->call('GET', "bank-accounts/{$bank}/register?from={$today->modify('-85 days')->format('Y-m-d')}&to={$today->format('Y-m-d')}");
            $rows = ['Txn Date,Particulars,Reference,Debit,Credit'];
            $n = 0;
            foreach ($register['lines'] as $line) {
                $what = strtoupper(trim(($line['contact'] ? "TRF {$line['contact']} " : '') . ($line['memo'] ?? $line['description'] ?? '')));
                $rows[] = implode(',', [(new DateTimeImmutable($line['date']))->format('d/m/Y'), '"' . str_replace('"', "'", Str::limit($what, 60, '')) . '"',
                    'FT' . $today->format('y') . sprintf('%06d', 310000 + ++$n), $line['out'] === '0.00' ? '' : $line['out'], $line['in'] === '0.00' ? '' : $line['in']]);
            }
            $charge = $today->modify('-20 days')->format('d/m/Y');
            $rows[] = "{$charge},SERVICE CHARGE MONTHLY,,25.00,";
            $rows[] = "{$charge},SMS ALERT CHARGE,,2.50,";
            $rows[] = "{$today->modify('-3 days')->format('d/m/Y')},INTEREST ON SAVINGS,,,48.12";
            $this->call('POST', "bank-accounts/{$bank}/statements", ['csv' => implode("\n", $rows) . "\n", 'file_name' => 'cbe-statement.csv']);

            $charges = $accounts->firstWhere('system_key', 'bank_charges')['id'] ?? null;
            $service = collect($this->call('GET', "bank-accounts/{$bank}/statement-lines")['data'])->firstWhere('description', 'SERVICE CHARGE MONTHLY');
            if ($charges !== null && $service !== null) {
                $this->call('POST', "statement-lines/{$service['id']}/categorise", ['account_id' => $charges, 'memo' => 'Monthly account fee']);
            }
        } finally {
            $user->tokens()->where('name', 'demo-setup')->delete();
        }
        $say('Added the demo bank statement.');
    }

    /** Sample books inside the current fiscal year: opening balances, customers, invoices in every state, payments. */
    private function books(DateTimeImmutable $today, DateTimeImmutable $fiscalStart): void
    {
        $day = $this->dayIn($today, $fiscalStart);

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

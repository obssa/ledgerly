<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Banking\StatementCsv;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Statements as banks and wallets export them: the header is found and read by meaning, not by position. */
final class StatementCsvTest extends TestCase
{
    private const TODAY = '2026-11-16';

    public function test_a_bank_statement_with_a_letterhead_and_money_out_and_in_columns(): void
    {
        $csv = "\xEF\xBB\xBFCommercial Bank of Ethiopia\nAccount,1000123456789\n\n"
            . "Txn Date,Value Date,Particulars,Reference No,Debit,Credit,Balance\n"
            . "01/11/2026,01/11/2026,Balance b/f,,,,\"10,000.00\"\n"
            . "03/11/2026,03/11/2026,\"Transfer from Abebe, invoice INV-0001\",FT26307AB1,,\"5,017.45\",\"15,017.45\"\n"
            . "05/11/2026,05/11/2026,Service charge,,15.00,,\"15,002.45\"\n"
            . "05/11/2026,05/11/2026,Service charge,,15.00,,\"14,987.45\"\n";
        $parsed = (new StatementCsv())->parse($csv, self::TODAY);

        $this->assertSame(['date' => 'Txn Date', 'description' => 'Particulars', 'reference' => 'Reference No', 'out' => 'Debit', 'in' => 'Credit', 'balance' => 'Balance'],
            array_intersect_key($parsed['columns'], array_flip(['date', 'description', 'reference', 'out', 'in', 'balance'])));
        $this->assertSame(['5017.45', '-15.00', '-15.00'], array_column($parsed['lines'], 'amount'));   // the balance brought forward has no amount
        $this->assertSame('2026-11-03', $parsed['lines'][0]['posted_on']);
        $this->assertSame('Transfer from Abebe, invoice INV-0001', $parsed['lines'][0]['description']);
        $this->assertSame('FT26307AB1', $parsed['lines'][0]['reference']);
        $this->assertSame('15017.45', $parsed['lines'][0]['balance_after']);
        $this->assertNotSame($parsed['lines'][1]['external_id'], $parsed['lines'][2]['external_id'], 'two charges are two lines');

        // The same statement read again gives the same ids, so importing it twice adds nothing.
        $this->assertSame(array_column($parsed['lines'], 'external_id'), array_column((new StatementCsv())->parse($csv, self::TODAY)['lines'], 'external_id'));
    }

    public function test_a_wallet_export_with_times_a_signed_amount_and_semicolons(): void
    {
        $csv = "Transaction Time;Transaction No;Transaction Type;Details;Amount (ETB);Balance\n"
            . "2026-11-04 09:12:44;DK41XQ;Receive money;From 0911 000 111;1,200.00;1,200.00\n"
            . "2026-11-04 18:30:01;DK41XR;Pay merchant;;-350.5;849.50\n";
        $lines = (new StatementCsv())->parse($csv, self::TODAY)['lines'];

        $this->assertSame(['1200.00', '-350.50'], array_column($lines, 'amount'));
        $this->assertSame(['2026-11-04', '2026-11-04'], array_column($lines, 'posted_on'));
        $this->assertSame(['DK41XQ', 'DK41XR'], array_column($lines, 'reference'));
        $this->assertSame(['From 0911 000 111', 'Pay merchant'], array_column($lines, 'description'), 'the type stands in for missing details');
    }

    public function test_one_amount_with_a_dr_cr_flag_or_brackets(): void
    {
        $lines = (new StatementCsv())->parse("Date,Narration,Amount,Dr/Cr\n05-Nov-2026,ATM,500.00,DR\n06-Nov-2026,Deposit,750,CR\n07-Nov-2026,Fee,(2.50),\n", self::TODAY)['lines'];
        $this->assertSame(['-500.00', '750.00', '-2.50'], array_column($lines, 'amount'));
        $this->assertSame(['2026-11-05', '2026-11-06', '2026-11-07'], array_column($lines, 'posted_on'));
    }

    public function test_unreadable_rows_are_named_and_nothing_is_imported(): void
    {
        $errors = $this->errorsOf("Date,Description,Amount\n31/02/2026,Bad date,10\n05/11/2026,Too precise,10.555\n05/11/2099,Future,1\n05/11/2026,Fine,1\n");
        $this->assertSame([
            'Row 2: "31/02/2026" is not a date we can read (use 2026-11-05 or 05/11/2026).',
            'Row 3: the amount is not a number with at most 2 decimals.',
            'Row 4: dated 2099-11-05, after today.',
        ], $errors);

        $this->assertSame(['No header row found: the statement needs a date column and an amount column (or money in and money out columns).'],
            $this->errorsOf("Name,Phone\nAbebe,0911\n"));
        $this->assertSame(['The statement has no lines with an amount.'], $this->errorsOf("Date,Description,Amount\n05/11/2026,Nothing,0\n"));
    }

    /** @return list<string> */
    private function errorsOf(string $csv): array
    {
        try {
            (new StatementCsv())->parse($csv, self::TODAY);
        } catch (ValidationException $e) {
            return $e->errors()['csv'];
        }
        $this->fail('The statement was accepted.');
    }
}

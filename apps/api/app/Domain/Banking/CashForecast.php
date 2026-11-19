<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Module 5: the cash forecast, week by week (13 by default). It starts from what the bank, cash and wallet accounts
 * hold today (the ledger, in the base currency) and adds what is expected in and out each week: what customers still
 * owe on posted invoices by their due date, what is still owed on posted bills by theirs, and the post-dated cheques
 * held, by their cheque date. Anything already overdue is in the first week. Foreign-currency documents count at their
 * own rate. Nothing is stored: it is read from the books as they stand, and it posts nothing.
 */
final class CashForecast
{
    public const MONEY = ['bank', 'cash', 'mobile_money'];

    /** @return array<string, mixed> */
    public function weeks(string $companyId, string $today, int $weeks = 13): array
    {
        $start = new DateTimeImmutable($today);
        $end = $start->modify('+' . ($weeks * 7 - 1) . ' days')->format('Y-m-d');

        $opening = (string) (DB::table('journal_lines as l')->join('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'l.company_id')->on('e.id', '=', 'l.journal_entry_id'))
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $companyId)->whereIn('a.subtype', self::MONEY)->where('e.entry_date', '<=', $today)
            ->selectRaw('coalesce(sum(l.debit - l.credit), 0)::numeric(20,2)::text AS b')->value('b') ?? '0.00');

        // What is still due on posted documents, in the base currency, by the week it falls in (overdue: the first).
        $due = DB::table('documents')->where('company_id', $companyId)->whereIn('status', ['posted', 'part_paid'])->where('amount_due', '>', 0)
            ->whereIn('type', ['invoice', 'bill'])->where(fn ($q) => $q->whereNull('due_date')->orWhere('due_date', '<=', $end))
            ->get(['id', 'type', 'number', 'contact_id', 'due_date', 'issue_date', 'amount_due', 'fx_rate']);
        $cheques = DB::table('post_dated_cheques as p')->join('accounts as a', 'a.id', '=', 'p.account_id')
            ->where(['p.company_id' => $companyId, 'p.status' => 'held'])->where('p.cheque_date', '<=', $end)
            ->get(['p.id', 'p.direction', 'p.cheque_number', 'p.cheque_date', 'p.amount', 'p.currency']);

        $rows = [];
        for ($w = 0; $w < $weeks; $w++) {
            $from = $start->modify('+' . ($w * 7) . ' days');
            $rows[] = ['week' => $w + 1, 'from' => $from->format('Y-m-d'), 'to' => $from->modify('+6 days')->format('Y-m-d'),
                'receivables' => '0.00', 'payables' => '0.00', 'cheques_in' => '0.00', 'cheques_out' => '0.00', 'in' => '0.00', 'out' => '0.00', 'net' => '0.00', 'closing' => '0.00'];
        }
        $weekOf = function (?string $date) use ($start, $weeks): int {
            if ($date === null) {
                return 0;
            }
            $days = (int) $start->diff(new DateTimeImmutable($date))->format('%r%a');

            return max(0, min($weeks - 1, intdiv(max(0, $days), 7)));
        };
        $overdue = ['receivables' => '0.00', 'payables' => '0.00'];
        foreach ($due as $d) {
            $base = bcmul((string) $d->amount_due, (string) $d->fx_rate, 2);
            $key = $d->type === 'invoice' ? 'receivables' : 'payables';
            $date = $d->due_date !== null ? substr((string) $d->due_date, 0, 10) : substr((string) $d->issue_date, 0, 10);
            if ($date < $today) {
                $overdue[$key] = bcadd($overdue[$key], $base, 2);
            }
            $i = $weekOf($date);
            $rows[$i][$key] = bcadd($rows[$i][$key], $base, 2);
        }
        $baseCurrency = (string) DB::table('companies')->where('id', $companyId)->value('base_currency');
        foreach ($cheques as $c) {
            // A held cheque in another currency counts at the company's latest rate for it, or not at all without one.
            $rate = $c->currency === $baseCurrency ? '1'
                : (string) (DB::table('exchange_rates')->where(['company_id' => $companyId, 'currency' => $c->currency])->where('rate_date', '<=', $today)
                    ->orderByDesc('rate_date')->value('rate') ?? '0');
            $base = bcmul((string) $c->amount, $rate, 2);
            $i = $weekOf(substr((string) $c->cheque_date, 0, 10));
            $key = $c->direction === 'in' ? 'cheques_in' : 'cheques_out';
            $rows[$i][$key] = bcadd($rows[$i][$key], $base, 2);
        }

        $balance = $opening;
        $lowest = null;
        foreach ($rows as &$r) {
            $r['in'] = bcadd($r['receivables'], $r['cheques_in'], 2);
            $r['out'] = bcadd($r['payables'], $r['cheques_out'], 2);
            $r['net'] = bcsub($r['in'], $r['out'], 2);
            $balance = bcadd($balance, $r['net'], 2);
            $r['closing'] = $balance;
            if ($lowest === null || bccomp($balance, $lowest['closing'], 2) < 0) {
                $lowest = ['week' => $r['week'], 'from' => $r['from'], 'closing' => $balance];
            }
        }
        unset($r);

        return ['as_of' => $today, 'weeks' => $weeks, 'opening' => $opening, 'overdue' => $overdue, 'data' => $rows, 'lowest' => $lowest,
            'closing' => $balance, 'goes_below_zero' => $lowest !== null && bccomp($lowest['closing'], '0', 2) < 0];
    }
}

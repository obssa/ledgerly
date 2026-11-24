<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Ledger\Money;
use App\Domain\Ledger\RecurringJournals;
use App\Domain\Sales\RecurringInvoices;
use App\Domain\Tax\TaxCalculator;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Module 5: the cash forecast, week by week (13 by default). It starts from what the bank, cash and wallet accounts
 * hold today (the ledger, in the base currency) and adds what is expected in and out each week: what customers still
 * owe on posted invoices by their due date, what is still owed on posted bills by theirs, and the post-dated cheques
 * held, by their cheque date; and what the recurring journals (their cash lines, on each date) and recurring invoices
 * (their total, by the customer's terms after each date) will bring in or pay out; and confirmed sales and purchase
 * orders, for what is not yet on a posted invoice or bill, by the date wanted plus the contact's terms. A bill with a
 * supplier payment scheduled for it (103) goes out on the planned day instead of its due date, for the planned amount.
 * Anything already overdue is in the first week. Foreign-currency documents count at their own rate. Nothing is stored:
 * it is read from the books as they stand, and it posts nothing.
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
                'receivables' => '0.00', 'payables' => '0.00', 'cheques_in' => '0.00', 'cheques_out' => '0.00', 'recurring_in' => '0.00', 'recurring_out' => '0.00', 'orders_in' => '0.00', 'orders_out' => '0.00', 'scheduled_out' => '0.00', 'in' => '0.00', 'out' => '0.00', 'net' => '0.00', 'closing' => '0.00'];
        }
        $weekOf = function (?string $date) use ($start, $weeks): int {
            if ($date === null) {
                return 0;
            }
            $days = (int) $start->diff(new DateTimeImmutable($date))->format('%r%a');

            return max(0, min($weeks - 1, intdiv(max(0, $days), 7)));
        };
        $overdue = ['receivables' => '0.00', 'payables' => '0.00'];
        $bills = [];   // each bill counted above: its week, whether overdue, its rate (for a scheduled payment of it)
        foreach ($due as $d) {
            $base = bcmul((string) $d->amount_due, (string) $d->fx_rate, 2);
            $key = $d->type === 'invoice' ? 'receivables' : 'payables';
            $date = $d->due_date !== null ? substr((string) $d->due_date, 0, 10) : substr((string) $d->issue_date, 0, 10);
            if ($date < $today) {
                $overdue[$key] = bcadd($overdue[$key], $base, 2);
            }
            $i = $weekOf($date);
            $rows[$i][$key] = bcadd($rows[$i][$key], $base, 2);
            if ($d->type === 'bill') {
                $bills[$d->id] = ['week' => $i, 'overdue' => $date < $today, 'rate' => (string) $d->fx_rate];
            }
        }

        // Scheduled supplier payments (103): each planned amount leaves its bill's due-date week and goes out on the day
        // planned (overdue: the first week; after the window: not counted). Only what the bill still owes is counted.
        $planned = DB::table('scheduled_payment_bills as sb')
            ->join('scheduled_payments as s', fn ($j) => $j->on('s.company_id', '=', 'sb.company_id')->on('s.id', '=', 'sb.scheduled_payment_id'))
            ->join('documents as d', fn ($j) => $j->on('d.company_id', '=', 'sb.company_id')->on('d.id', '=', 'sb.document_id'))
            ->where(['sb.company_id' => $companyId, 's.status' => 'scheduled'])->whereIn('d.status', ['posted', 'part_paid'])->where('d.amount_due', '>', 0)
            ->get(['sb.document_id', 'sb.amount', 'd.amount_due', 'd.fx_rate', 's.pay_on']);
        foreach ($planned as $p) {
            $amount = bccomp((string) $p->amount, (string) $p->amount_due, 2) > 0 ? (string) $p->amount_due : (string) $p->amount;
            $base = bcmul($amount, (string) $p->fx_rate, 2);
            if (isset($bills[$p->document_id])) {
                $b = $bills[$p->document_id];
                $rows[$b['week']]['payables'] = bcsub($rows[$b['week']]['payables'], $base, 2);
                if ($b['overdue']) {
                    $overdue['payables'] = bcsub($overdue['payables'], $base, 2);
                }
            }
            $on = substr((string) $p->pay_on, 0, 10);
            if ($on <= $end) {
                $i = $weekOf(max($on, $today));
                $rows[$i]['scheduled_out'] = bcadd($rows[$i]['scheduled_out'], $base, 2);
            }
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

        // What the recurring entries will move, each time they come due in the window: a recurring journal's lines on the
        // cash accounts on its date; a recurring invoice's total, received by the customer's terms after its date.
        $rateOf = fn (string $currency) => $currency === $baseCurrency ? '1'
            : (string) (DB::table('exchange_rates')->where(['company_id' => $companyId, 'currency' => $currency])->where('rate_date', '<=', $today)
                ->orderByDesc('rate_date')->value('rate') ?? '0');
        $cash = DB::table('accounts')->where('company_id', $companyId)->whereIn('subtype', self::MONEY)->pluck('id')->flip();
        $occurrences = function (object $t, callable $advance) use ($end): array {
            $dates = [];
            for ($on = substr((string) $t->next_on, 0, 10); $on <= $end && ($t->ends_on === null || $on <= substr((string) $t->ends_on, 0, 10)) && count($dates) < 60; $on = $advance($on, (string) $t->frequency)) {
                $dates[] = $on;
            }

            return $dates;
        };
        foreach (DB::table('recurring_journals')->where(['company_id' => $companyId, 'is_active' => true])->where('next_on', '<=', $end)->get() as $t) {
            $net = '0';
            foreach (json_decode((string) $t->lines, true, 512, JSON_THROW_ON_ERROR) as $l) {
                if (isset($cash[$l['account_id'] ?? ''])) {
                    $net = bcadd($net, bcsub((string) ($l['debit'] ?? '0'), (string) ($l['credit'] ?? '0'), 2), 2);
                }
            }
            if (bccomp($net, '0', 2) === 0) {
                continue;
            }
            foreach ($occurrences($t, RecurringJournals::advance(...)) as $on) {
                $i = $weekOf(max($on, $today));
                $key = bccomp($net, '0', 2) > 0 ? 'recurring_in' : 'recurring_out';
                $rows[$i][$key] = bcadd($rows[$i][$key], ltrim($net, '-'), 2);
            }
        }
        $tax = new TaxCalculator();
        $taxRates = DB::table('tax_rates')->where('company_id', $companyId)->pluck('rate', 'id');
        foreach (DB::table('recurring_invoices as r')->join('contacts as c', 'c.id', '=', 'r.contact_id')
            ->where(['r.company_id' => $companyId, 'r.is_active' => true])->where('r.next_on', '<=', $end)
            ->get(['r.next_on', 'r.ends_on', 'r.frequency', 'r.lines', 'r.currency', 'r.prices_include_tax', 'c.payment_terms_days']) as $t) {
            // Totalled the way the invoice it makes will be (SaveInvoiceDraft::calculate).
            $amounts = [];
            foreach (json_decode((string) $t->lines, true, 512, JSON_THROW_ON_ERROR) as $l) {
                $amounts[] = $tax->line(Money::of((string) $l['unit_price'], (string) $t->currency), (string) $l['quantity'],
                    (string) ($l['discount_rate'] ?? '0'), (string) ($taxRates[$l['tax_rate_id'] ?? ''] ?? '0'), (bool) $t->prices_include_tax);
            }
            $total = $tax->document($amounts, (string) $t->currency)->total->toDecimalString();
            $base = bcmul($total, $rateOf((string) $t->currency), 2);
            if (bccomp($base, '0', 2) <= 0) {
                continue;
            }
            $terms = (int) ($t->payment_terms_days ?? 0);
            foreach ($occurrences($t, RecurringInvoices::advance(...)) as $on) {
                $paid = (new DateTimeImmutable($on))->modify("+{$terms} days")->format('Y-m-d');
                if ($paid <= $end) {
                    $i = $weekOf($paid);
                    $rows[$i]['recurring_in'] = bcadd($rows[$i]['recurring_in'], $base, 2);
                }
            }
        }

        // Confirmed orders: what is not yet on a posted invoice or bill made from them (those are counted above, by their due
        // date), expected on the date the order wants (else its date) plus the customer's or supplier's terms.
        $made = DB::table('documents')->where('company_id', $companyId)->whereIn('type', ['invoice', 'bill'])->whereIn('status', ['posted', 'part_paid', 'paid'])
            ->whereNotNull('source_document_id')->groupBy('source_document_id')->select('source_document_id')
            ->selectRaw('sum(total)::numeric(20,2)::text AS total')->pluck('total', 'source_document_id');
        foreach (DB::table('documents as o')->join('contacts as c', 'c.id', '=', 'o.contact_id')->where(['o.company_id' => $companyId, 'o.status' => 'accepted'])
            ->whereIn('o.type', ['sales_order', 'purchase_order'])->get(['o.id', 'o.type', 'o.issue_date', 'o.due_date', 'o.total', 'o.currency', 'c.payment_terms_days']) as $o) {
            $left = bcsub((string) $o->total, (string) ($made[$o->id] ?? '0'), 2);
            if (bccomp($left, '0', 2) <= 0) {
                continue;
            }
            $terms = (int) ($o->payment_terms_days ?? 0);
            $paid = (new DateTimeImmutable(substr((string) ($o->due_date ?? $o->issue_date), 0, 10)))->modify("+{$terms} days")->format('Y-m-d');
            if ($paid > $end) {
                continue;
            }
            $i = $weekOf(max($paid, $today));
            $key = $o->type === 'sales_order' ? 'orders_in' : 'orders_out';
            $rows[$i][$key] = bcadd($rows[$i][$key], bcmul($left, $rateOf((string) $o->currency), 2), 2);
        }

        $balance = $opening;
        $lowest = null;
        foreach ($rows as &$r) {
            $r['in'] = bcadd(bcadd(bcadd($r['receivables'], $r['cheques_in'], 2), $r['recurring_in'], 2), $r['orders_in'], 2);
            $r['out'] = bcadd(bcadd(bcadd(bcadd($r['payables'], $r['cheques_out'], 2), $r['recurring_out'], 2), $r['orders_out'], 2), $r['scheduled_out'], 2);
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

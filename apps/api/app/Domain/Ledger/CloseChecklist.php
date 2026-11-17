<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Module 6, period close: what to check before closing the books through a date. Each item is ok or a warning with
 * what is left to do and where to do it. Light controls (CLAUDE.md): the checklist informs the close and is kept in
 * its audit record; it does not block it. Every query is static SQL with bound values.
 */
final class CloseChecklist
{
    /** @return array{period_end: string, items: list<array<string, mixed>>, warnings: int} */
    public function run(Company $company, string $periodEnd): array
    {
        $id = $company->getKey();
        $base = $company->base_currency;
        $items = [
            $this->banksReconciled($id, $periodEnd),
            $this->statementLines($id, $periodEnd),
            $this->stockValued($id, $base, $periodEnd),
            $this->openDocuments($id, $periodEnd),
            $this->journalRequests($id, $periodEnd),
            $this->depreciation($id, $periodEnd),
            $this->undeposited($id, $base, $periodEnd),
            $this->balanced($id, $base, $periodEnd),
        ];

        return ['period_end' => $periodEnd, 'items' => $items, 'warnings' => count(array_filter($items, fn ($i) => $i['status'] !== 'ok'))];
    }

    /** Every bank and wallet account with entries up to the date is reconciled through it. */
    private function banksReconciled(string $company, string $end): array
    {
        $accounts = DB::table('accounts as a')->where('a.company_id', $company)->whereIn('a.subtype', ['bank', 'mobile_money'])
            ->whereExists(fn ($q) => $q->from('journal_lines as l')->whereColumn('l.company_id', 'a.company_id')->whereColumn('l.account_id', 'a.id')->where('l.entry_date', '<=', $end))
            ->orderBy('a.code')->get(['a.id', 'a.code', 'a.name']);
        $through = DB::table('reconciliations')->where(['company_id' => $company, 'status' => 'completed'])->whereIn('account_id', $accounts->pluck('id')->all())
            ->groupBy('account_id')->select('account_id')->selectRaw('max(statement_date)::text AS through')->pluck('through', 'account_id');
        $behind = $accounts->filter(fn ($a) => ($through[$a->id] ?? '') < $end)->values();

        return ['key' => 'banks_reconciled', 'label' => 'Bank and wallet accounts reconciled', 'status' => $behind->isEmpty() ? 'ok' : 'warn',
            'detail' => $behind->isEmpty() ? "Every bank and wallet account is reconciled through {$end}." : "{$behind->count()} not reconciled through {$end}.",
            'items' => $behind->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->code} {$a->name}", 'note' => isset($through[$a->id]) ? "reconciled through {$through[$a->id]}" : 'never reconciled',
                'link' => "/banking/{$a->id}/reconcile"])->all()];
    }

    /** No imported statement line up to the date is still waiting to be matched, categorised or excluded. */
    private function statementLines(string $company, string $end): array
    {
        $rows = DB::table('bank_transactions as t')->join('accounts as a', 'a.id', '=', 't.account_id')
            ->where(['t.company_id' => $company, 't.status' => 'imported'])->where('t.posted_on', '<=', $end)
            ->groupBy('a.id', 'a.code', 'a.name')->select('a.id', 'a.code', 'a.name')->selectRaw('count(*) AS n')->get();
        $n = (int) $rows->sum('n');

        return ['key' => 'statement_lines', 'label' => 'Statement lines reviewed', 'status' => $n === 0 ? 'ok' : 'warn',
            'detail' => $n === 0 ? 'No statement line is waiting.' : "{$n} statement line" . ($n === 1 ? ' is' : 's are') . ' still to review.',
            'items' => $rows->map(fn ($r) => ['id' => $r->id, 'label' => "{$r->code} {$r->name}", 'note' => "{$r->n} to review", 'link' => "/banking/{$r->id}/statement"])->all()];
    }

    /** The inventory account agrees with the stock on hand valued move by move. */
    private function stockValued(string $company, string $base, string $end): array
    {
        $stock = Money::of((string) DB::table('stock_moves')->where('company_id', $company)->where('moved_on', '<=', $end)->sum('amount'), $base);
        $inventory = DB::table('accounts')->where(['company_id' => $company, 'system_key' => 'inventory'])->value('id');
        $ledger = Money::of((string) ($inventory ? DB::table('journal_lines')->where(['company_id' => $company, 'account_id' => $inventory])
            ->where('entry_date', '<=', $end)->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS v')->value('v') : '0'), $base);
        $difference = $ledger->minus($stock);

        return ['key' => 'stock_valued', 'label' => 'Stock valued', 'status' => $difference->isZero() ? 'ok' : 'warn',
            'detail' => $difference->isZero() ? "Stock on hand ({$stock->toDecimalString()}) agrees with the inventory account."
                : "The inventory account ({$ledger->toDecimalString()}) and the stock on hand ({$stock->toDecimalString()}) differ by {$difference->toDecimalString()}.",
            'items' => [], 'link' => "/reports/inventory-valuation?as_of={$end}"];
    }

    /** Documents of the period still drafts or waiting for approval: not in the books yet. */
    private function openDocuments(string $company, string $end): array
    {
        $rows = DB::table('documents')->where('company_id', $company)->whereIn('status', ['draft', 'awaiting_approval'])->where('issue_date', '<=', $end)
            ->groupBy('type', 'status')->select('type', 'status')->selectRaw('count(*) AS n')->get();
        $n = (int) $rows->sum('n');
        $label = fn ($r) => str_replace('_', ' ', $r->type) . ($r->n > 1 ? 's' : '') . ' ' . str_replace('_', ' ', $r->status);

        return ['key' => 'open_documents', 'label' => 'Documents of the period posted', 'status' => $n === 0 ? 'ok' : 'warn',
            'detail' => $n === 0 ? 'No draft or unapproved document is dated in the period.' : "{$n} document" . ($n === 1 ? ' is' : 's are') . ' dated in the period and not posted.',
            'items' => $rows->map(fn ($r) => ['id' => null, 'label' => $label($r), 'note' => (string) $r->n,
                'link' => in_array($r->type, ['bill', 'vendor_credit'], true) ? '/bills' : ($r->type === 'expense' ? '/expenses' : '/invoices')])->all()];
    }

    private function journalRequests(string $company, string $end): array
    {
        $n = DB::table('journal_requests')->where(['company_id' => $company, 'status' => 'pending'])->where('entry_date', '<=', $end)->count();

        return ['key' => 'journal_requests', 'label' => 'Manual journals decided', 'status' => $n === 0 ? 'ok' : 'warn',
            'detail' => $n === 0 ? 'No manual journal of the period is waiting for approval.' : "{$n} manual journal" . ($n === 1 ? ' is' : 's are') . ' waiting for approval.',
            'items' => [], 'link' => '/journal'];
    }

    /** Depreciation posted through the last month end of the period, when assets were due by then (042). */
    private function depreciation(string $company, string $end): array
    {
        $date = new \DateTimeImmutable($end);
        $monthEnd = $date->format('Y-m-d') === $date->modify('last day of this month')->format('Y-m-d') ? $end : $date->modify('last day of previous month')->format('Y-m-d');
        $due = DB::table('fixed_assets')->where(['company_id' => $company, 'status' => 'active'])->where('depreciation_starts_on', '<=', $monthEnd)->count();
        $latest = DB::table('depreciation_runs')->where('company_id', $company)->max('period_end');
        $ok = $due === 0 || ($latest !== null && $latest >= $monthEnd);

        return ['key' => 'depreciation', 'label' => 'Depreciation posted', 'status' => $ok ? 'ok' : 'warn',
            'detail' => $due === 0 ? 'No fixed asset is depreciated by then.' : ($ok ? "Depreciation is posted through {$latest}."
                : 'Depreciation is posted ' . ($latest ? "through {$latest}" : 'for no month yet') . ": run it through {$monthEnd}."),
            'items' => [], 'link' => '/fixed-assets'];
    }

    /** Receipts kept as undeposited funds at the date: money received but not yet in a bank. */
    private function undeposited(string $company, string $base, string $end): array
    {
        $account = DB::table('accounts')->where(['company_id' => $company, 'system_key' => 'undeposited_funds'])->value('id');
        $balance = Money::of((string) ($account ? DB::table('journal_lines')->where(['company_id' => $company, 'account_id' => $account])
            ->where('entry_date', '<=', $end)->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS v')->value('v') : '0'), $base);

        return ['key' => 'undeposited', 'label' => 'Receipts banked', 'status' => $balance->isZero() ? 'ok' : 'warn',
            'detail' => $balance->isZero() ? 'Nothing is waiting to be banked.' : "{$balance->toDecimalString()} of receipts was not yet banked at {$end}.",
            'items' => [], 'link' => '/banking'];
    }

    /** Debits equal credits in the books up to the date (the database keeps them so; checked here for the record). */
    private function balanced(string $company, string $base, string $end): array
    {
        $row = DB::table('journal_lines')->where('company_id', $company)->where('entry_date', '<=', $end)
            ->selectRaw('coalesce(sum(debit), 0)::numeric(20,2)::text AS d, coalesce(sum(credit), 0)::numeric(20,2)::text AS c')->first();
        $ok = Money::of((string) $row->d, $base)->equals(Money::of((string) $row->c, $base));

        return ['key' => 'balanced', 'label' => 'Books balance', 'status' => $ok ? 'ok' : 'warn',
            'detail' => $ok ? "Debits and credits agree ({$row->d})." : "Debits ({$row->d}) and credits ({$row->c}) differ.", 'items' => [], 'link' => "/reports/trial-balance?as_of={$end}"];
    }
}

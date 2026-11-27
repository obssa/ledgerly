<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Revaluation;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * QuickBooks' exchange gain and loss reports, for a company that works in more than one currency.
 *
 * Realized gains and losses: every exchange difference booked when foreign money actually moved (a payment at another
 * rate than its invoice or bill, a credit applied later, a refund, a write-off, a currency transfer), each with its
 * transaction; month-end revaluations and their reversals are left out (they are unrealized).
 *
 * Unrealized gains and losses: at a date, every open foreign item (a foreign bank or wallet balance, an open invoice,
 * credit note, bill or supplier credit, a payment not yet applied), its value in the books and at the day's rate,
 * and the difference a revaluation would book. Read only; a currency with no rate in the week is listed, not guessed.
 */
final class ExchangeGainsController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly Revaluation $revaluation)
    {
    }

    public function realized(Request $request): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $q = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $company = $this->current->get();
        $fx = Account::system('fx_gain_loss')->getKey();

        // Fixed SQL: the company, the account and the dates are bindings (CLAUDE.md rule 1).
        $rows = DB::select(<<<'SQL'
            SELECT l.entry_date AS date, e.id AS entry_id, e.number AS entry, e.source_type, e.memo, l.description,
                   coalesce(c.name, pc.name) AS name, p.id AS payment_id, p.direction, p.reference, d.id AS document_id, d.type AS document_type, d.number AS document_number,
                   CASE WHEN e.currency <> co.base_currency THEN e.currency END AS currency,
                   CASE WHEN p.id IS NOT NULL THEN p.amount::text END AS foreign_amount,
                   CASE WHEN e.currency <> co.base_currency THEN e.fx_rate::text END AS exchange_rate,
                   (l.credit - l.debit)::numeric(20,2)::text AS gain
              FROM journal_lines l
              JOIN journal_entries e ON e.company_id = l.company_id AND e.id = l.journal_entry_id
              JOIN companies co ON co.id = l.company_id
              LEFT JOIN payments p ON p.company_id = l.company_id AND p.journal_entry_id = e.id
              LEFT JOIN documents d ON d.company_id = l.company_id AND d.id = e.source_id AND e.source_type IN ('invoice', 'credit_note', 'bill', 'vendor_credit', 'bad_debt')
              LEFT JOIN contacts c ON c.company_id = l.company_id AND c.id = coalesce(l.contact_id, d.contact_id)
              LEFT JOIN contacts pc ON pc.company_id = l.company_id AND pc.id = p.contact_id
             WHERE l.company_id = ? AND l.account_id = ? AND l.entry_date BETWEEN ? AND ?
               AND e.source_type <> 'revaluation'
               AND NOT (e.source_type = 'reversal' AND EXISTS (SELECT 1 FROM journal_entries o WHERE o.company_id = e.company_id AND o.id = e.source_id AND o.source_type = 'revaluation'))
             ORDER BY l.entry_date, e.number, l.line_no
            SQL, [$company->getKey(), $fx, $q['from'], $q['to']]);

        $total = \Brick\Math\BigDecimal::zero();
        foreach ($rows as $r) {
            $total = $total->plus($r->gain);
        }

        return response()->json(['from' => $q['from'], 'to' => $q['to'], 'currency' => $company->base_currency, 'lines' => $rows,
            'total' => (string) $total->toScale(2)]);
    }

    public function unrealized(Request $request): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $q = $request->validate(['as_of' => ['required', 'date_format:Y-m-d']]);
        $company = $this->current->get();
        $preview = $this->revaluation->preview($company, $q['as_of'], [], true);
        $names = DB::table('contacts')->where('company_id', $company->getKey())
            ->whereIn('id', array_values(array_filter(array_column($preview['lines'], 'contact_id'))))->pluck('name', 'id');

        return response()->json([...$preview, 'currency' => $company->base_currency,
            'lines' => array_map(fn (array $l) => [...$l, 'name' => $l['contact_id'] ? ($names[$l['contact_id']] ?? null) : null], $preview['lines']),
            'missing_rates' => array_keys(array_filter($preview['rates'], fn ($r) => $r === null))]);
    }
}

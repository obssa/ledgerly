<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Banking\ChequeNumbers;
use App\Domain\Ledger\Models\Account;
use App\Domain\Sales\Models\Payment;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Module 5, cheques: the cheque register of a bank account. Cheques written are issued until the bank shows them
 * (matched to a statement line, or cleared in a reconciliation), or cancelled when the payment is reversed. Cheques
 * received are received, cleared, bounced or reversed. Nothing here is stored twice: the state is read from the payment,
 * the statement and the reconciliation.
 */
final class ChequeController extends Controller
{
    public function __construct(private readonly CurrentCompany $current)
    {
    }

    public function index(Request $request, Account $bankAccount): JsonResponse
    {
        $this->authorizeCompany('banking.view');
        $q = $request->validate(['direction' => ['nullable', Rule::in(['out', 'in'])]]);
        $direction = $q['direction'] ?? 'out';
        $companyId = $this->current->id();

        $cheques = Payment::query()->with('contact:id,name')
            ->where(['account_id' => $bankAccount->getKey(), 'method' => 'cheque', 'direction' => $direction])
            ->orderByDesc('payment_date')->orderByDesc('created_at')->limit(500)->get();
        // The cheque's line on this account, and whether the bank has shown it.
        $lines = DB::table('journal_lines')->where(['company_id' => $companyId, 'account_id' => $bankAccount->getKey()])
            ->whereIn('journal_entry_id', $cheques->pluck('journal_entry_id')->filter()->all())->pluck('id', 'journal_entry_id');
        $onStatement = DB::table('bank_transactions')->where('company_id', $companyId)->whereIn('journal_line_id', $lines->values()->all())->pluck('journal_line_id')->flip();
        $reconciled = DB::table('reconciliation_lines as rl')
            ->join('reconciliations as r', fn ($j) => $j->on('r.company_id', '=', 'rl.company_id')->on('r.id', '=', 'rl.reconciliation_id'))
            ->where(['rl.company_id' => $companyId, 'r.status' => 'completed'])->whereIn('rl.journal_line_id', $lines->values()->all())
            ->pluck('rl.journal_line_id')->flip();

        $rows = $cheques->map(function (Payment $p) use ($lines, $onStatement, $reconciled, $direction) {
            $line = $lines[$p->journal_entry_id] ?? null;
            $cleared = $line !== null && (isset($onStatement[$line]) || isset($reconciled[$line]));
            $status = match (true) {
                $p->bounced_on !== null => 'bounced',
                $p->status === 'reversed' => $direction === 'out' ? 'cancelled' : 'reversed',
                $cleared => 'cleared',
                default => $direction === 'out' ? 'issued' : 'received',
            };

            return [
                'id' => $p->getKey(), 'kind' => $p->kind, 'number' => $p->cheque_number, 'date' => substr((string) $p->payment_date, 0, 10),
                'contact' => $p->contact?->name, 'currency' => $p->currency, 'amount' => (string) $p->amount, 'reference' => $p->reference,
                'status' => $status, 'bounced_on' => $p->bounced_on === null ? null : substr((string) $p->bounced_on, 0, 10),
            ];
        });

        return response()->json([
            'account' => ['id' => $bankAccount->getKey(), 'code' => $bankAccount->code, 'name' => $bankAccount->name, 'currency' => $bankAccount->currency ?? $this->current->get()->base_currency],
            'direction' => $direction,
            'data' => $rows->values(),
            'counts' => $rows->countBy('status'),
            'next_number' => $direction === 'out' ? ChequeNumbers::next($bankAccount->getKey()) : null,
            'can' => ['bounce' => (bool) $this->current->membership()?->can('payments.reverse')],
        ]);
    }
}

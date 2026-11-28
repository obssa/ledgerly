<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Models\Account;
use App\Domain\Reports\ReceivablesReport;
use App\Domain\Sales\Models\Contact;
use App\Domain\Tenancy\Support\CurrentCompany;
use App\Notifications\StatementSent;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * QuickBooks' Create statements, for customers (Sales, Statements) and suppliers (Purchases, Statements): one
 * statement per customer or supplier, of one of three kinds, for the period chosen.
 * - Balance forward: what was owed before the period, every movement in it with a running balance, the balance at the end.
 * - Open item: only what is still owed at the end of the period, each document with its days overdue.
 * - Transaction statement: every transaction of the period, without the balance brought forward.
 * Each has its ageing at the end of the period. Customers' statements are emailed to them (an email each, audited).
 * Who: one customer or supplier, or all with a balance, all overdue, or all of them (100 at most).
 */
final class ContactStatementsController extends Controller
{
    public const TYPES = ['balance_forward', 'open_item', 'transaction'];

    private const MAX = 100;

    public function __construct(private readonly ReceivablesReport $receivables, private readonly CurrentCompany $current, private readonly AuditLogger $audit)
    {
    }

    public function customers(Request $request): JsonResponse
    {
        $this->authorizeCompany('contacts.view');

        return response()->json($this->build($request, payable: false));
    }

    public function suppliers(Request $request): JsonResponse
    {
        $this->authorizeCompany('contacts.view');

        return response()->json($this->build($request, payable: true));
    }

    /** Each customer's statement by email, to their address on file: the ones without one are named back. */
    public function email(Request $request): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        $request->validate(['contact_ids' => ['required', 'array', 'min:1', 'max:' . self::MAX], 'contact_ids.*' => ['uuid'], 'note' => ['nullable', 'string', 'max:1000']]);
        $data = $this->build($request, payable: false, only: $request->input('contact_ids'));
        if (count($data['statements']) !== count(array_unique((array) $request->input('contact_ids')))) {
            throw ValidationException::withMessages(['contact_ids' => ['Choose customers of this company.']]);
        }
        $company = $this->current->get();
        $sent = [];
        $skipped = [];
        foreach ($data['statements'] as $s) {
            $to = trim((string) ($s['contact']['email'] ?? ''));
            $key = 'statement-email:' . $s['contact']['id'];
            if ($to === '' || RateLimiter::tooManyAttempts($key, 1)) {
                $skipped[] = ['id' => $s['contact']['id'], 'name' => $s['contact']['name'], 'reason' => $to === '' ? 'no_email' : 'just_sent'];
                continue;
            }
            RateLimiter::hit($key, 300);
            Notification::route('mail', [$to => (string) $s['contact']['name']])->notify(new StatementSent(
                company: (string) ($company->trading_name ?: $company->legal_name), companyTin: $company->tin ? (string) $company->tin : null,
                replyTo: $company->email ? (string) $company->email : null, customer: (string) $s['contact']['name'], type: $data['type'],
                from: (string) \App\Support\DateFormat::format(new DateTimeImmutable($data['from']), $company),
                to: (string) \App\Support\DateFormat::format(new DateTimeImmutable($data['to']), $company), currency: $data['currency'],
                opening: $s['opening'], closing: $s['closing'],
                lines: array_map(fn (array $l) => ['date' => (string) \App\Support\DateFormat::format(new DateTimeImmutable($l['date']), $company),
                    'reference' => (string) ($l['reference'] ?? ''), 'amount' => $l['amount'], 'balance' => $l['balance'] ?? null], $s['lines']),
                note: $request->input('note'),
            ));
            $this->audit->record($company->getKey(), 'statement_emailed', 'customer', $s['contact']['id'], null,
                ['sent_to' => $to, 'type' => $data['type'], 'from' => $data['from'], 'to' => $data['to'], 'closing' => $s['closing']], userId: $request->user()->getKey());
            $sent[] = ['id' => $s['contact']['id'], 'name' => $s['contact']['name'], 'sent_to' => $to];
        }

        return response()->json(['sent' => $sent, 'skipped' => $skipped]);
    }

    /**
     * @param list<string>|null $only the customers asked for by id (emailing)
     * @return array<string, mixed>
     */
    private function build(Request $request, bool $payable, ?array $only = null): array
    {
        $q = $request->validate([
            'type' => ['nullable', Rule::in(self::TYPES)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'contact_id' => ['nullable', 'uuid'],
            'who' => ['nullable', Rule::in(['balance', 'overdue', 'all'])],
        ]);
        $company = $this->current->get();
        $today = $this->current->today();
        $to = $q['to'] ?? $today;
        $from = $q['from'] ?? (new DateTimeImmutable($to))->modify('first day of this month')->format('Y-m-d');
        $type = $q['type'] ?? 'balance_forward';
        $currency = (string) $company->base_currency;
        $account = Account::system($payable ? 'accounts_payable' : 'accounts_receivable')->getKey();
        $ageing = $this->receivables->ageing($company->getKey(), $account, $currency, $to, $payable ? 'bill' : 'invoice');
        $rows = collect($ageing['rows'])->keyBy('contact_id');

        // Who gets one, through the company-scoped model (CLAUDE.md rule 4).
        $contacts = Contact::query()->where($payable ? 'is_vendor' : 'is_customer', true);
        if ($only !== null) {
            $contacts->whereIn('id', $only);
        } elseif (! empty($q['contact_id'])) {
            $contacts->whereKey($q['contact_id']);
        } else {
            $who = $q['who'] ?? 'balance';
            $ids = match ($who) {
                'balance' => $rows->keys()->all(),
                'overdue' => $rows->filter(fn (array $r) => collect($ageing['buckets'])->reject(fn ($b) => $b === 'current')->contains(fn ($b) => bccomp((string) $r[$b], '0', 2) > 0))->keys()->all(),
                default => null,
            };
            if ($ids !== null) {
                $contacts->whereIn('id', $ids);
            } else {
                $contacts->where('is_active', true);
            }
        }
        $list = $contacts->orderByRaw('lower(name)')->limit(self::MAX + 1)->get(['id', 'name', 'tax_id', 'email', 'address']);
        if (! empty($q['contact_id']) && $list->isEmpty()) {
            throw ValidationException::withMessages(['contact_id' => [$payable ? 'Not a supplier of this company.' : 'Not a customer of this company.']]);
        }
        $more = $list->count() > self::MAX;
        $documents = collect($ageing['documents'])->groupBy('contact_id');

        $statements = $list->take(self::MAX)->map(function (Contact $c) use ($type, $from, $to, $payable, $company, $account, $currency, $rows, $documents, $ageing) {
            $row = $rows->get($c->getKey());
            $ageingRow = array_combine([...$ageing['buckets'], 'credits', 'total'], array_map(fn ($k) => (string) ($row[$k] ?? '0.00'), [...$ageing['buckets'], 'credits', 'total']));
            if ($type === 'open_item') {
                $open = $documents->get($c->getKey(), collect())->sortBy('issue_date')->values();

                return ['contact' => $c->only(['id', 'name', 'tax_id', 'email', 'address']), 'opening' => null, 'closing' => $ageingRow['total'], 'ageing' => $ageingRow,
                    'lines' => $open->map(fn (array $d) => ['date' => $d['issue_date'], 'type' => $d['type'], 'reference' => $d['number'], 'document_id' => $d['id'],
                        'due_date' => $d['due_date'], 'days_overdue' => $d['days_late'], 'amount' => $d['amount'], 'total' => $d['base_total'], 'balance' => null])->all()];
            }
            $st = $this->receivables->statement($company->getKey(), $account, $c->getKey(), $currency, $from, $to, $payable);

            return ['contact' => $c->only(['id', 'name', 'tax_id', 'email', 'address']), 'opening' => $type === 'balance_forward' ? $st['opening'] : null,
                'closing' => $st['closing'], 'ageing' => $ageingRow,
                'lines' => array_map(fn (array $l) => ['date' => $l['date'], 'type' => $l['type'], 'reference' => $l['reference'], 'document_id' => $l['document_id'],
                    'memo' => $l['memo'], 'due_date' => $l['due_date'], 'charge' => $payable ? $l['credit'] : $l['debit'], 'payment' => $payable ? $l['debit'] : $l['credit'],
                    'amount' => bcsub($payable ? $l['credit'] : $l['debit'], $payable ? $l['debit'] : $l['credit'], 2), 'balance' => $l['balance']], $st['lines'])];
        })->values()->all();

        return ['type' => $type, 'from' => $from, 'to' => $to, 'currency' => $currency, 'buckets' => $ageing['buckets'], 'statements' => $statements, 'more' => $more];
    }
}

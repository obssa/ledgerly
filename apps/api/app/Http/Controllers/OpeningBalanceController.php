<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Sales\Actions\EnterOpeningDocument;
use App\Domain\Sales\Models\Document;
use App\Domain\Ledger\Currency;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 1, opening balances: the trial balance on the conversion date (the last bank statement date before the
 * company switched to Evenbooks), posted as one journal entry through the PostingService.
 *
 * If the lines do not balance, the difference goes to Opening balance equity and is flagged, so the books balance
 * and someone looks at it before the first period is closed. Entering them again reverses the previous opening entry
 * and posts the new one; nothing is overwritten.
 *
 * Receivables, payables and stock are not entered here as one figure: they come from the open invoices, bills and
 * stock counts behind them, so the control account always equals its detail.
 */
final class OpeningBalanceController extends Controller
{
    /** Control accounts whose balance must come from their detail records. */
    public const FROM_DETAIL = ['accounts_receivable', 'accounts_payable', 'inventory'];

    public const DIFFERENCE = 'Opening balance difference: check it before closing the first period';

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function show(CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('accounting.view');

        return response()->json($this->present($current->get()->conversion_date?->format('Y-m-d'), $current->get()->base_currency));
    }

    public function update(Request $request, CurrentCompany $current, PostingService $posting): JsonResponse
    {
        $this->authorizeCompany('accounting.opening');
        $company = $current->get();

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:2000'],
            'lines.*.account_id' => ['required', 'uuid', 'distinct'],
            'lines.*.debit' => ['nullable', 'decimal:0,2', 'min:0', 'max:999999999999999999'],
            'lines.*.credit' => ['nullable', 'decimal:0,2', 'min:0', 'max:999999999999999999'],
        ], ['lines.*.account_id.distinct' => 'Each account appears once.']);

        $date = $company->conversion_date?->format('Y-m-d');
        if ($date === null) {
            throw ValidationException::withMessages(['conversion_date' => ['Set the conversion date under Company settings first: opening balances are as at that date.']]);
        }

        // Loaded through the company-scoped model: an id from another company is simply not found.
        $accounts = Account::query()->whereIn('id', array_column($data['lines'], 'account_id'))->get()->keyBy('id');
        $base = $company->base_currency;
        $lines = [];
        $errors = [];
        foreach ($data['lines'] as $i => $line) {
            $account = $accounts[$line['account_id']] ?? null;
            $debit = Money::of($line['debit'] ?? '0', $base);
            $credit = Money::of($line['credit'] ?? '0', $base);
            if ($account === null) {
                $errors["lines.{$i}.account_id"] = ['Not an account of this company.'];
            } elseif (! $account->is_active) {
                $errors["lines.{$i}.account_id"] = ["{$account->code} {$account->name} is not active."];
            } elseif (in_array($account->system_key, self::FROM_DETAIL, true)) {
                $errors["lines.{$i}.account_id"] = ["{$account->name} comes from the open invoices, bills or stock behind it, not one figure."];
            } elseif ($account->currency !== null && $account->currency !== $base) {
                $errors["lines.{$i}.account_id"] = ["{$account->name} is kept in {$account->currency}; its opening balance is entered with the foreign amount."];
            } elseif (! $debit->isZero() && ! $credit->isZero()) {
                $errors["lines.{$i}.debit"] = ['A line is a debit or a credit, not both.'];
            } elseif (! $debit->isZero() || ! $credit->isZero()) {
                $lines[] = $debit->isZero() ? JournalLineDraft::credit($account->getKey(), $credit) : JournalLineDraft::debit($account->getKey(), $debit);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => ['Enter at least one balance.']]);
        }

        $userId = $request->user()->getKey();
        DB::transaction(function () use ($company, $date, $lines, $base, $posting, $userId) {
            $previous = $this->openingEntry();
            if ($previous !== null) {
                $posting->reverse($previous->getKey(), new DateTimeImmutable($date), $userId);
            }

            $draft = new JournalDraft($company->getKey(), new DateTimeImmutable($date), $base, $base, '1', 'Opening balances', 'opening');
            foreach ($lines as $line) {
                $draft->add($line);
            }
            $difference = $draft->totalDebit()->minus($draft->totalCredit());
            if (! $difference->isZero()) {
                $equity = Account::system('opening_balance_equity')->getKey();
                $draft->add($difference->isNegative()
                    ? JournalLineDraft::debit($equity, $difference->negated(), null, self::DIFFERENCE)
                    : JournalLineDraft::credit($equity, $difference, null, self::DIFFERENCE));
            }
            $entry = $posting->post($draft, $userId);

            $this->audit->record($company->getKey(), $previous === null ? 'opening_balances_entered' : 'opening_balances_replaced', 'journal_entry', $entry->getKey(),
                $previous === null ? null : ['journal_entry' => $previous->number],
                ['journal_entry' => $entry->number, 'as_at' => $date, 'lines' => count($lines), 'difference' => $difference->toDecimalString()],
                userId: $userId);
        });

        return response()->json($this->present($date, $base));
    }

    /** An invoice a customer still owed on the conversion date, from the old books (057). */
    public function invoice(Request $request, EnterOpeningDocument $enter): JsonResponse
    {
        return $this->openDocument($request, $enter, 'invoice');
    }

    /** A bill the company still owed a supplier on the conversion date, from the old books (057). */
    public function bill(Request $request, EnterOpeningDocument $enter): JsonResponse
    {
        return $this->openDocument($request, $enter, 'bill');
    }

    private function openDocument(Request $request, EnterOpeningDocument $enter, string $type): JsonResponse
    {
        $this->authorizeCompany('accounting.opening');
        $data = $request->validate([
            // The company's own customer or supplier: the exists rule reads through row-level security.
            'contact_id' => ['required', 'uuid', Rule::exists('contacts', 'id')->where($type === 'bill' ? 'is_vendor' : 'is_customer', true)],
            'number' => [$type === 'invoice' ? 'required' : 'nullable', 'string', 'max:40'],
            'reference' => [$type === 'bill' ? 'required' : 'prohibited', 'string', 'max:60'],   // the supplier's invoice number
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'currency' => ['required', Rule::in(Currency::all())],
            'fx_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:999999999999999999'],   // what was still owed
        ]);
        $document = $enter->handle($type, $data, $request->user()->getKey());

        return response()->json(['id' => $document->getKey(), 'type' => $document->type, 'number' => $document->number, 'reference' => $document->reference,
            'total' => $document->total, 'amount_due' => $document->amount_due, 'journal_entry_id' => $document->journal_entry_id], 201);
    }

    private function openingEntry(): ?JournalEntry
    {
        return JournalEntry::query()->where(['source_type' => 'opening', 'status' => 'posted'])->orderByDesc('number')->first();
    }

    /** @return array<string, mixed> */
    private function present(?string $date, string $base): array
    {
        $entry = $this->openingEntry()?->load('lines');
        $lines = [];
        $difference = Money::zero($base);
        $equity = Account::query()->where('system_key', 'opening_balance_equity')->value('id');
        foreach ($entry?->lines ?? [] as $line) {
            if ($line->account_id === $equity && $line->description === self::DIFFERENCE) {
                $difference = Money::of($line->credit, $base)->minus(Money::of($line->debit, $base));
                continue;
            }
            $lines[] = ['account_id' => $line->account_id, 'debit' => (string) $line->debit, 'credit' => (string) $line->credit];
        }

        return [
            'conversion_date' => $date,
            'base_currency' => $base,
            'journal_entry' => $entry === null ? null : ['id' => $entry->getKey(), 'number' => $entry->number, 'entry_date' => $entry->entry_date->format('Y-m-d')],
            'lines' => $lines,
            // Debits minus credits of the lines entered; booked to Opening balance equity so the books still balance.
            'difference' => $difference->toDecimalString(),
            'from_detail' => self::FROM_DETAIL,
            // Open invoices and bills brought in at conversion (057), oldest first; voided ones are left out.
            'documents' => Document::query()->where('opening', true)->where('status', '<>', 'void')->with('contact:id,name')
                ->orderBy('type')->orderBy('issue_date')->orderBy('id')->limit(500)
                ->get(['id', 'type', 'number', 'reference', 'contact_id', 'issue_date', 'due_date', 'currency', 'total', 'amount_due', 'status'])
                ->map(fn (Document $d) => [...$d->only(['id', 'type', 'number', 'reference', 'issue_date', 'due_date', 'currency', 'total', 'amount_due', 'status']),
                    'contact' => $d->contact?->only(['id', 'name'])])->values(),
            // Whether the signed-in member may enter them; the API checks again on save.
            'can' => ['enter' => (bool) app(CurrentCompany::class)->membership()?->can('accounting.opening')],
        ];
    }
}

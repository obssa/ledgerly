<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Tenancy\Support\CurrentCompany;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Module 5, petty cash: counting a cash box. The counter is named; the notes and coins can be listed. Whatever the count
 * finds different from the books is posted to cash over and short on the day of the count, so afterwards the books hold
 * what was counted (030). Counts are kept as they were made; to correct one, count again.
 */
final class CashCountController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly PostingService $posting, private readonly AuditLogger $audit)
    {
    }

    public function index(Account $bankAccount): JsonResponse
    {
        $this->authorizeCompany('banking.view');
        $counts = DB::table('cash_counts as k')->leftJoin('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'k.company_id')->on('e.id', '=', 'k.journal_entry_id'))
            ->where(['k.company_id' => $this->current->id(), 'k.account_id' => $bankAccount->getKey()])
            ->orderByDesc('k.counted_on')->orderByDesc('k.created_at')->limit(200)
            ->get(['k.id', 'k.counted_on', 'k.counted_by_name', 'k.denominations', 'k.counted', 'k.book_balance', 'k.difference', 'k.note', 'k.created_at', 'e.number as entry']);

        return response()->json([
            'data' => $counts->map(fn ($k) => [
                'id' => $k->id, 'counted_on' => $k->counted_on, 'counted_by' => $k->counted_by_name, 'denominations' => (object) json_decode((string) $k->denominations, true),
                'counted' => (string) $k->counted, 'book_balance' => (string) $k->book_balance, 'difference' => (string) $k->difference,
                'entry' => $k->entry, 'note' => $k->note, 'recorded_at' => $k->created_at,
            ])->values(),
            'can' => ['count' => (bool) $this->current->membership()?->can('banking.count')],
        ]);
    }

    public function store(Request $request, Account $bankAccount): JsonResponse
    {
        $this->authorizeCompany('banking.count');
        $data = $request->validate([
            'counted_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'counted_by_name' => ['required', 'string', 'min:2', 'max:120'],
            'denominations' => ['nullable', 'array', 'max:30'],
            'denominations.*' => ['integer', 'min:0', 'max:1000000'],
            'counted' => ['nullable', 'decimal:0,2', 'min:0', 'required_without:denominations'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $company = $this->current->get();
        $base = $company->base_currency;
        if ($bankAccount->subtype !== 'cash' || $bankAccount->system_key === 'undeposited_funds' || ($bankAccount->currency !== null && $bankAccount->currency !== $base)) {
            throw ValidationException::withMessages(['counted' => ["{$bankAccount->name} is not a cash box in {$base}: count cash in a cash account."]]);
        }

        // The notes and coins (value => how many) add up to what was counted; if both are given, they must agree.
        $denominations = [];
        $total = Money::zero($base);
        foreach ($data['denominations'] ?? [] as $value => $pieces) {
            if (! preg_match('/^\d{1,7}(\.\d{1,2})?$/', (string) $value) || BigDecimal::of((string) $value)->isZero()) {
                throw ValidationException::withMessages(['denominations' => ["\"{$value}\" is not a note or coin value."]]);
            }
            if ((int) $pieces > 0) {
                $denominations[(string) $value] = (int) $pieces;
                $total = $total->plus(Money::of((string) $value, $base)->multipliedBy((string) $pieces));
            }
        }
        if (isset($data['counted']) && $denominations !== [] && ! Money::of($data['counted'], $base)->equals($total)) {
            throw ValidationException::withMessages(['counted' => ["The notes and coins add up to {$total->toDecimalString()}, not {$data['counted']}."]]);
        }
        $counted = $denominations !== [] ? $total : Money::of($data['counted'] ?? '0', $base);

        $userId = $request->user()->getKey();
        $result = DB::transaction(function () use ($bankAccount, $data, $company, $base, $counted, $denominations, $userId) {
            DB::table('accounts')->where('id', $bankAccount->getKey())->lockForUpdate()->first(['id']);   // one count of a box at a time
            $books = Money::of((string) DB::table('journal_lines')->where(['company_id' => $company->getKey(), 'account_id' => $bankAccount->getKey()])
                ->where('entry_date', '<=', $data['counted_on'])->sum(DB::raw('debit - credit')), $base);
            $difference = $counted->minus($books);
            $entry = null;
            if (! $difference->isZero()) {
                $short = $difference->isNegative();
                $size = $short ? $difference->negated() : $difference;
                $overShort = Account::system('cash_over_short')->getKey();
                $memo = 'Cash count ' . ($short ? 'short' : 'over') . ": {$bankAccount->name}, counted by {$data['counted_by_name']}";
                $draft = new JournalDraft($company->getKey(), new DateTimeImmutable($data['counted_on']), $base, $base, '1', mb_substr($memo, 0, 255), 'cash_count');
                $draft->add($short ? JournalLineDraft::debit($overShort, $size, null, 'Cash short') : JournalLineDraft::debit($bankAccount->getKey(), $size, null, 'Cash over'));
                $draft->add($short ? JournalLineDraft::credit($bankAccount->getKey(), $size, null, 'Cash short') : JournalLineDraft::credit($overShort, $size, null, 'Cash over'));
                $entry = $this->posting->post($draft, $userId);
            }
            $id = (string) Str::uuid7();
            DB::table('cash_counts')->insert([
                'id' => $id, 'company_id' => $company->getKey(), 'account_id' => $bankAccount->getKey(), 'counted_on' => $data['counted_on'],
                'counted_by_name' => trim($data['counted_by_name']), 'recorded_by' => $userId, 'denominations' => json_encode((object) $denominations, JSON_THROW_ON_ERROR),
                'counted' => $counted->toDecimalString(), 'book_balance' => $books->toDecimalString(), 'difference' => $difference->toDecimalString(),
                'journal_entry_id' => $entry?->getKey(), 'note' => $data['note'] ?? null,
            ]);

            return ['id' => $id, 'counted' => $counted->toDecimalString(), 'book_balance' => $books->toDecimalString(), 'difference' => $difference->toDecimalString(), 'entry' => $entry?->number];
        });
        $this->audit->record($company->getKey(), 'cash_counted', 'bank_account', $bankAccount->getKey(), null,
            [...$result, 'counted_on' => $data['counted_on'], 'counted_by' => trim($data['counted_by_name'])], $data['note'] ?? null, userId: $userId);

        return response()->json($result, 201);
    }
}

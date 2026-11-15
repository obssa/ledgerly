<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Dimension;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Reports\ReportService;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class LedgerController extends Controller
{
    public function __construct(private readonly CurrentCompany $current)
    {
    }

    public function accounts(): JsonResponse
    {
        $this->authorizeCompany('accounting.view');

        return response()->json(['data' => Account::query()->orderBy('code')->get()]);
    }

    public function entries(Request $request): JsonResponse
    {
        $this->authorizeCompany('accounting.view');

        return response()->json(JournalEntry::query()->with('lines')->orderByDesc('number')->cursorPaginate(50));
    }

    /** Manual journal entry (accountants). Same posting path as every module. */
    public function storeEntry(Request $request, PostingService $posting): JsonResponse
    {
        $this->authorizeCompany('journals.create');

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'memo' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:500'],
            'lines.*.account_id' => ['required', 'uuid'],
            'lines.*.debit' => ['nullable', 'decimal:0,2', 'min:0'],
            'lines.*.credit' => ['nullable', 'decimal:0,2', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.location_id' => ['nullable', 'uuid'],
            'lines.*.class_id' => ['nullable', 'uuid'],
            'lines.*.project_id' => ['nullable', 'uuid'],
        ]);
        $dimensions = $this->activeDimensions($data['lines']);

        $company = $this->current->get();
        $draft = new JournalDraft($company->getKey(), new DateTimeImmutable($data['date']), $company->base_currency, $company->base_currency, '1', $data['memo'] ?? null);

        foreach ($data['lines'] as $i => $line) {
            $debit = Money::of($line['debit'] ?? '0', $company->base_currency);
            $credit = Money::of($line['credit'] ?? '0', $company->base_currency);
            $draft->add($debit->isZero()
                ? JournalLineDraft::credit($line['account_id'], $credit, null, $line['description'] ?? null, null, $dimensions[$i])
                : JournalLineDraft::debit($line['account_id'], $debit, null, $line['description'] ?? null, null, $dimensions[$i]));
        }

        return response()->json($posting->post($draft, $request->user()->getKey()), 201);
    }

    /**
     * The location, class and project of each line, each loaded through the company-scoped model and checked to be
     * active and of the right kind. @return list<array<string, string>>
     */
    private function activeDimensions(array $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            foreach (Dimension::COLUMNS as $column) {
                if (! empty($line[$column])) {
                    $ids[] = $line[$column];
                }
            }
        }
        $found = Dimension::query()->whereIn('id', array_unique($ids))->where('is_active', true)->get(['id', 'kind'])->keyBy('id');

        $out = [];
        $errors = [];
        foreach (array_values($lines) as $i => $line) {
            $out[$i] = [];
            foreach (Dimension::COLUMNS as $kind => $column) {
                if (empty($line[$column])) {
                    continue;
                }
                if (($found[$line[$column]]->kind ?? null) !== $kind) {
                    $errors["lines.{$i}.{$column}"] = ["Not an active {$kind} of this company."];
                } else {
                    $out[$i][$column] = $line[$column];
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    public function reverse(Request $request, JournalEntry $entry, PostingService $posting): JsonResponse
    {
        $this->authorizeCompany('journals.create');
        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];

        return response()->json($posting->reverse($entry->getKey(), new DateTimeImmutable($date), $request->user()->getKey()), 201);
    }

    public function trialBalance(Request $request, ReportService $reports): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $asOf = $request->validate(['as_of' => ['required', 'date_format:Y-m-d']])['as_of'];

        return response()->json(['data' => $reports->trialBalance($this->current->id(), $asOf)]);
    }

    /** Balance sheet; `equation.balanced` reports whether Assets = Liabilities + Equity holds. */
    public function balanceSheet(Request $request, ReportService $reports): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $asOf = $request->validate(['as_of' => ['required', 'date_format:Y-m-d']])['as_of'];
        $company = $this->current->get();

        return response()->json($reports->balanceSheet($company->getKey(), $company->base_currency, $asOf));
    }

    public function binCard(Request $request, ReportService $reports): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $q = $request->validate([
            'item_id' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return response()->json($reports->binCard($this->current->id(), $q['item_id'], $q['warehouse_id'], $q['from'], $q['to']));
    }

    public function profitAndLoss(Request $request, ReportService $reports): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $q = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'compare_from' => ['nullable', 'date_format:Y-m-d'],
            'compare_to' => ['nullable', 'date_format:Y-m-d', 'required_with:compare_from'],
        ]);

        $company = $this->current->get();

        return response()->json($reports->profitAndLoss(
            $company->getKey(), $company->base_currency, $q['from'], $q['to'], $q['compare_from'] ?? null, $q['compare_to'] ?? null,
        ));
    }
}

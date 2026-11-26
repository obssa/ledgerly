<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ledger\Currency;
use App\Domain\Purchases\ExpenseClaims;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Module 3: employee expense claims (083). A member with expenses.claim makes, sees and submits their own claims; a
 * member who sees bills (bills.view) sees everyone's, and one who approves bills (bills.approve) approves them through
 * the bill routes, never their own. Claims are loaded through the company-scoped binding; another member's claim is not
 * found by a claimant who cannot see bills.
 */
final class ExpenseClaimController extends Controller
{
    use Concerns\FiltersByDate;

    public function __construct(private readonly ExpenseClaims $claims, private readonly CurrentCompany $current)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('expenses.claim');
        $range = $this->dateRange($request);
        $all = (bool) $this->current->membership()?->can('bills.view');
        $claims = Document::query()->where('type', 'bill')->whereNotNull('claimed_by')
            ->when(! $all, fn ($q) => $q->where('claimed_by', $request->user()->getKey()))
            ->with('contact:id,name')->when(true, fn ($q) => $this->withinDates($q, 'issue_date', $range))->orderByDesc('issue_date')->orderByDesc('created_at')->limit(500)
            ->get(['id', 'number', 'status', 'contact_id', 'issue_date', 'currency', 'total', 'amount_due', 'claimed_by', 'notes']);

        $today = $this->current->today();

        return response()->json(['data' => $claims, 'can' => ['see_all' => $all, 'approve' => (bool) $this->current->membership()?->can('bills.approve')],
            // What a claim may name, for the form: the company's expense accounts in use and the VAT or TOT rates in force.
            'options' => [
                'accounts' => \App\Domain\Ledger\Models\Account::query()->where(['type' => 'expense', 'is_active' => true])->orderBy('code')->get(['id', 'code', 'name']),
                'tax_rates' => DB::table('tax_rates')->whereIn('kind', ['vat', 'tot'])->where('effective_from', '<=', $today)
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))->orderBy('code')->get(['id', 'code', 'name']),
            ]]);
    }

    public function show(Request $request, Document $expenseClaim): JsonResponse
    {
        $this->authorizeCompany('expenses.claim');
        $this->mine($request, $expenseClaim, allowViewers: true);

        return response()->json($this->present($request, $expenseClaim->load(['lines', 'contact:id,name'])));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('expenses.claim');
        $data = $request->validate([
            'issue_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'currency' => ['nullable', Rule::in(Currency::all())],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.amount' => ['required', 'decimal:0,2', 'gt:0', 'max:999999999999'],
            // What it was for: an expense account of the company's own (row-level security), in use.
            'lines.*.account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where(fn (Builder $q) => $q->where(['type' => 'expense', 'is_active' => true]))],
            'lines.*.tax_rate_id' => ['nullable', 'uuid', Rule::exists('tax_rates', 'id')->whereIn('kind', ['vat', 'tot'])],
            'lines.*.project_id' => ['nullable', 'uuid', Rule::exists('dimensions', 'id')->where('kind', 'project')->where('is_active', true)],
        ]);
        $data['currency'] ??= $this->current->get()->base_currency;

        return response()->json($this->present($request, $this->claims->claim($data, $request->user())->load(['lines', 'contact:id,name'])), 201);
    }

    public function submit(Request $request, Document $expenseClaim): JsonResponse
    {
        $this->authorizeCompany('expenses.claim');
        $this->mine($request, $expenseClaim, allowViewers: false);

        return response()->json($this->present($request, $this->claims->submit($expenseClaim, $request->user())->load(['lines', 'contact:id,name'])));
    }

    /** A claim of the person asking (or, to read, any claim for someone who sees bills); anything else is not found. */
    private function mine(Request $request, Document $doc, bool $allowViewers): void
    {
        $own = $doc->claimed_by !== null && $doc->claimed_by === $request->user()->getKey();
        $sees = $allowViewers && $doc->claimed_by !== null && (bool) $this->current->membership()?->can('bills.view');
        if (! $own && ! $sees) {
            throw new NotFoundHttpException();
        }
    }

    /** @return array<string, mixed> */
    private function present(Request $request, Document $claim): array
    {
        $own = $claim->claimed_by === $request->user()->getKey();

        return [...$claim->toArray(), 'can' => [
            'submit' => $own && $claim->status === 'draft',
            'approve' => ! $own && $claim->status === 'awaiting_approval' && (bool) $this->current->membership()?->can('bills.approve'),
            'attach' => $own && in_array($claim->status, ['draft', 'awaiting_approval'], true),
        ]];
    }
}

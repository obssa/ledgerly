<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Sales\Actions\QuoteWorkflow;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\CurrentCompany;
use App\Domain\Tenancy\Support\DatabaseTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 2: a quote seen and answered by the customer through the link in its email (065), without an account. The
 * token is checked by its hash outside any company (quote_link_lookup, quote_link_answer); only then is the quote read,
 * or moved, inside its own company's context, and only that quote. Throttled like the other sign-in-free routes.
 */
final class QuoteLinkController extends Controller
{
    private const GONE = 'This link is not valid any more: ask the company to send the quote again.';

    public function __construct(private readonly CurrentCompany $current, private readonly QuoteWorkflow $workflow)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $hash = hash('sha256', $this->token($request));
        $link = DB::transaction(fn () => DB::selectOne('SELECT company_id, document_id, expires_at, answered_at, answer FROM quote_link_lookup(?)', [$hash]));
        if ($link === null) {
            return response()->json(['message' => self::GONE], 404);
        }

        return DatabaseTenantContext::run(null, $link->company_id, function () use ($link) {
            $company = Company::query()->findOrFail($link->company_id);
            $quote = Document::query()->where('type', 'quote')->with(['lines', 'contact:id,name'])->findOrFail($link->document_id);

            return response()->json([
                'company' => ['name' => (string) ($company->trading_name ?: $company->legal_name), 'tin' => $company->tin, 'email' => $company->email, 'phone' => $company->phone],
                'customer' => $quote->contact?->name,
                'quote' => [
                    'number' => $quote->number, 'issue_date' => $quote->issue_date->format('Y-m-d'), 'valid_until' => $quote->due_date?->format('Y-m-d'),
                    'currency' => $quote->currency, 'subtotal' => (string) $quote->subtotal, 'tax_total' => (string) $quote->tax_total, 'total' => (string) $quote->total,
                    'notes' => $quote->notes, 'status' => $quote->status,
                    'lines' => $quote->lines->map(fn ($l) => ['description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_price' => (string) $l->unit_price,
                        'amount' => bcadd((string) $l->line_subtotal, (string) $l->line_tax, 2)])->values(),
                ],
                'answer' => $link->answer, 'answered_at' => $link->answered_at, 'expires_at' => $link->expires_at,
                'can_answer' => $link->answer === null && $quote->status === 'sent',
            ]);
        });
    }

    public function answer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'answer' => ['required', 'in:accepted,declined'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'note' => ['nullable', 'string', 'max:300'],
        ], ['name.required' => 'Your name, as it goes on the record of the answer.']);
        $hash = hash('sha256', $data['token']);

        return DB::transaction(function () use ($data, $hash) {
            $taken = DB::selectOne('SELECT company_id, document_id FROM quote_link_answer(?, ?, ?)', [$hash, $data['answer'], trim($data['name'])]);
            if ($taken === null) {
                throw ValidationException::withMessages(['token' => [self::GONE]]);
            }
            // From here on, inside the quote's own company, as no member of it: the customer answered.
            DatabaseTenantContext::set(null, $taken->company_id);
            $this->current->set(Company::query()->findOrFail($taken->company_id));
            try {
                $quote = Document::query()->where('type', 'quote')->findOrFail($taken->document_id);
                $done = $this->workflow->answerOnline($quote, $data['answer'] === 'accepted', trim($data['name']), $data['note'] ?? null);
            } catch (LedgerException $e) {
                throw ValidationException::withMessages(['answer' => [$e->getMessage()]]);
            } finally {
                $this->current->forget();
            }

            return response()->json(['answer' => $data['answer'], 'status' => $done->status, 'number' => $done->number]);
        });
    }

    private function token(Request $request): string
    {
        return $request->validate(['token' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/']])['token'];
    }
}

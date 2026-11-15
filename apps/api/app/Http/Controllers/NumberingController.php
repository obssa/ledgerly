<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Sales\DocumentNumbers;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Module 1, numbering: how each type of document is numbered. The counter itself is never set by hand: numbers are
 * taken in order when documents are posted, so they have no gaps (DocumentNumbers).
 */
final class NumberingController extends Controller
{
    public function __construct(private readonly DocumentNumbers $numbers, private readonly AuditLogger $audit)
    {
    }

    public function index(CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('company.view');
        $company = $current->get();
        $today = new DateTimeImmutable(app(CurrentCompany::class)->today());

        return response()->json(['data' => array_map(fn (string $type) => [
            ...$this->numbers->settings($company->getKey(), $type),
            'next_number' => $this->numbers->preview($company, $type, $today),
        ], array_keys(DocumentNumbers::DEFAULTS))]);
    }

    public function update(Request $request, CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('company.manage');
        $company = $current->get();

        $data = $request->validate([
            'doc_type' => ['required', Rule::in(array_keys(DocumentNumbers::DEFAULTS))],
            'mode' => ['required', Rule::in(['automatic', 'manual'])],
            'prefix' => ['present', 'nullable', 'string', 'regex:/^[A-Za-z0-9\/._-]{0,12}$/'],
            'include_year' => ['required', 'boolean'],
            'padding' => ['required', 'integer', 'between:1,8'],
        ], ['prefix.regex' => 'Up to 12 letters, digits and / . _ -']);
        $data['prefix'] ??= '';

        $before = $this->numbers->settings($company->getKey(), $data['doc_type']);
        DB::table('document_numbering')->upsert(
            [['company_id' => $company->getKey(), ...$data, 'updated_at' => now()]],
            ['company_id', 'doc_type'],
            ['mode', 'prefix', 'include_year', 'padding', 'updated_at'],
        );
        $after = $this->numbers->settings($company->getKey(), $data['doc_type']);

        $changes = AuditLogger::diff($before, $after);
        if ($changes !== []) {
            $this->audit->record($company->getKey(), 'numbering_changed', 'document_numbering', null,
                ['doc_type' => $data['doc_type'], ...array_map(fn (array $c) => $c['old'], $changes)],
                ['doc_type' => $data['doc_type'], ...array_map(fn (array $c) => $c['new'], $changes)],
                userId: $request->user()->getKey());
        }

        return response()->json([...$after, 'next_number' => $this->numbers->preview($company, $data['doc_type'], new DateTimeImmutable(app(CurrentCompany::class)->today()))]);
    }
}

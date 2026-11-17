<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tax\TaxAuditFile;
use App\Domain\Tax\TaxCalendar;
use App\Domain\Tax\TaxReturns;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 7: VAT and withholding returns. The worksheet of any period, built from the ledger (tax.view); filing a
 * return for a period that has ended, which keeps its figures and locks the period for that tax (tax.file); and
 * settling it, which posts the payment to the tax authority (tax.file).
 */
final class TaxReturnController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly TaxReturns $returns, private readonly AuditLogger $audit)
    {
    }

    /** The filing calendar: the past year's periods and the current one, each with its due date and where it stands. */
    public function calendar(TaxCalendar $calendar): JsonResponse
    {
        $this->authorizeCompany('tax.view');

        return response()->json([...$calendar->for($this->current->get(), $this->current->today()), 'today' => $this->current->today()]);
    }

    /** The tax audit file of a period (last month by default): its documents or its ledger lines, as CSV. */
    public function auditFile(Request $request, TaxAuditFile $file): JsonResponse
    {
        $this->authorizeCompany('tax.view');
        [, $from, $to] = $this->period($request, false);
        $part = $request->validate(['part' => ['nullable', Rule::in(['documents', 'ledger'])]])['part'] ?? 'ledger';
        $result = $part === 'documents' ? $file->documents($this->current->id(), $from, $to) : $file->ledger($this->current->id(), $from, $to);
        $this->audit->record($this->current->id(), 'tax_audit_file_exported', 'company', $this->current->id(), null,
            ['part' => $part, 'from' => $from, 'to' => $to, 'rows' => $result['rows']], userId: $request->user()->getKey());

        return response()->json(['filename' => "{$part}-{$from}-to-{$to}.csv", ...$result]);
    }

    /** The worksheet of a period: last month by default. */
    public function worksheet(Request $request): JsonResponse
    {
        $this->authorizeCompany('tax.view');
        [$kind, $from, $to] = $this->period($request, false);

        return response()->json([
            ...$this->returns->worksheet($this->current->get(), $kind, $from, $to),
            'filed' => $this->filedOver($kind, $from, $to),
            'can' => ['file' => (bool) $this->current->membership()?->can('tax.file') && $to < $this->current->today()],
        ]);
    }

    public function index(): JsonResponse
    {
        $this->authorizeCompany('tax.view');
        $rows = DB::table('tax_returns as r')->leftJoin('users as u', 'u.id', '=', 'r.filed_by')->where('r.company_id', $this->current->id())
            ->orderByDesc('r.period_end')->limit(200)
            ->get(['r.id', 'r.kind', 'r.period_start', 'r.period_end', 'r.filed_on', 'r.reference', 'r.output_tax', 'r.input_tax', 'r.net', 'u.name as filed_by', 'r.settled_on']);

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->row($r))->values(),
            'can' => ['file' => (bool) $this->current->membership()?->can('tax.file')],
        ]);
    }

    public function show(string $taxReturn): JsonResponse
    {
        $this->authorizeCompany('tax.view');
        $r = $this->load($taxReturn);
        $entry = $r->settlement_entry_id ? DB::table('journal_entries')->where(['company_id' => $this->current->id(), 'id' => $r->settlement_entry_id])->value('number') : null;

        return response()->json([
            ...$this->row($r), 'filed_by' => DB::table('users')->where('id', $r->filed_by)->value('name'),
            'figures' => json_decode((string) $r->figures, true, 512, JSON_THROW_ON_ERROR),
            'settlement' => $r->settlement_entry_id ? ['id' => $r->settlement_entry_id, 'number' => (int) $entry, 'date' => $r->settled_on] : null,
            'can' => ['settle' => $r->settlement_entry_id === null && \Brick\Math\BigDecimal::of((string) $r->output_tax)->isPositive() && (bool) $this->current->membership()?->can('tax.file')],
        ]);
    }

    /** File a return: the figures are built from the ledger now and kept as they are. */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('tax.file');
        [$kind, $from, $to] = $this->period($request, true);
        $today = $this->current->today();
        $data = $request->validate([
            'filed_on' => ['required', 'date_format:Y-m-d', 'after:' . $to, 'before_or_equal:' . $today],
            'reference' => ['nullable', 'string', 'max:100'],
        ], ['filed_on.after' => 'A return is filed after its period ends.']);
        if ($to >= $today) {
            throw ValidationException::withMessages(['period_end' => ['The period has not ended yet: file it after ' . $to . '.']]);
        }
        $id = $this->returns->file($this->current->get(), $kind, $from, $to, $data['filed_on'], $data['reference'] ?? null, $request->user()->getKey());
        $r = $this->load($id);
        $this->audit->record($this->current->id(), 'tax_return_filed', 'tax_return', $id, null,
            ['kind' => $kind, 'period_start' => $from, 'period_end' => $to, 'output_tax' => (string) $r->output_tax, 'input_tax' => (string) $r->input_tax,
                'net' => (string) $r->net, 'reference' => $r->reference], userId: $request->user()->getKey());

        return $this->show($id)->setStatusCode(201);
    }

    /** Settle a filed return: VAT payable against VAT receivable and the bank (or withholding payable against the bank). */
    public function settle(Request $request, string $taxReturn): JsonResponse
    {
        $this->authorizeCompany('tax.file');
        $r = $this->load($taxReturn);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after:' . $r->period_end, 'before_or_equal:' . $this->current->today()],
            'bank_account_id' => ['nullable', 'uuid'],
        ], ['date.after' => "A return is settled after its period ({$r->period_end})."]);
        if ($r->settlement_entry_id !== null) {
            throw ValidationException::withMessages(['date' => ["This return was settled on {$r->settled_on}."]]);
        }
        $result = $this->returns->settle($this->current->get(), $r, $data['date'], $data['bank_account_id'] ?? null, $request->user()->getKey());
        $this->audit->record($this->current->id(), 'tax_return_settled', 'tax_return', $r->id, null,
            ['date' => $data['date'], 'paid' => $result['paid'], 'entry' => $result['entry']['number']], userId: $request->user()->getKey());

        return $this->show($r->id);
    }

    /** @return array{0: string, 1: string, 2: string} kind, from, to (to defaults to the end of last month) */
    private function period(Request $request, bool $required): array
    {
        $q = $request->validate([
            'kind' => [$required ? 'required' : 'nullable', Rule::in(TaxReturns::KINDS)],
            'period_start' => [$required ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'period_end' => [$required ? 'required' : 'nullable', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $lastMonth = (new DateTimeImmutable($this->current->today()))->modify('first day of last month');
        $from = $q['period_start'] ?? $q['from'] ?? $lastMonth->format('Y-m-d');
        $to = $q['period_end'] ?? $q['to'] ?? $lastMonth->modify('last day of this month')->format('Y-m-d');
        if ($to < $from || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 366) {
            throw ValidationException::withMessages(['period_end' => ['A return covers up to a year, ending on or after it starts.']]);
        }

        return [$q['kind'] ?? 'vat', $from, $to];
    }

    /** @return list<array<string, string>> filed returns of the kind that cover part of the period */
    private function filedOver(string $kind, string $from, string $to): array
    {
        return DB::table('tax_returns')->where(['company_id' => $this->current->id(), 'kind' => $kind])
            ->where('period_start', '<=', $to)->where('period_end', '>=', $from)->orderBy('period_start')
            ->get(['id', 'period_start', 'period_end', 'filed_on'])->map(fn ($r) => (array) $r)->values()->all();
    }

    /** @return array<string, mixed> */
    private function row(object $r): array
    {
        return ['id' => $r->id, 'kind' => $r->kind, 'period_start' => $r->period_start, 'period_end' => $r->period_end, 'filed_on' => $r->filed_on,
            'reference' => $r->reference, 'output_tax' => (string) $r->output_tax, 'input_tax' => (string) $r->input_tax, 'net' => (string) $r->net,
            'filed_by' => $r->filed_by, 'settled_on' => $r->settled_on];
    }

    /** The return, if it is this company's; another company's is not found. */
    private function load(string $id): object
    {
        $r = DB::table('tax_returns')->where(['company_id' => $this->current->id(), 'id' => $id])->first();
        if ($r === null) {
            abort(404);
        }

        return $r;
    }
}

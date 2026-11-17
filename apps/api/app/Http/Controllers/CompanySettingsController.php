<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\CloseChecklist;
use App\Domain\Ledger\Currency;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\CountryPack;
use App\Domain\Tenancy\Support\CurrentCompany;
use App\Support\FiscalCalendar;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 1, company profile and fiscal settings: the company being worked in, read by every member and changed by
 * an owner or admin; and the period lock date, moved forward by an accountant and back only by an owner or admin.
 *
 * The foundation of the books (base currency, calendar, fiscal year start, conversion date) can change only while
 * nothing is posted; database/schema/011_company_settings.sql enforces the same for every caller.
 */
final class CompanySettingsController extends Controller
{
    public const LEGAL_FORMS = ['sole_proprietor', 'partnership', 'private_limited', 'share_company', 'cooperative',
        'non_profit', 'branch_of_foreign', 'public_body', 'other'];

    /** Fields fixed once one journal entry exists. The country never changes here: a new country is a new company. */
    public const FOUNDATION = ['base_currency', 'calendar', 'fiscal_year_start_month', 'conversion_date'];

    /** Approval limits a company can set: invoices, bills and manual journals above them need someone else to approve. */
    public const APPROVAL_LIMITS = ['invoice_above', 'bill_above', 'journal_above'];

    private const PROFILE = ['legal_name', 'trading_name', 'legal_form', 'industry', 'tin', 'vat_registered', 'vat_number',
        'phone', 'email', 'website', 'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'timezone',
        'accounting_basis'];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function show(CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('company.view');

        return response()->json($this->present($current->get()));
    }

    public function update(Request $request, CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('company.manage');

        $company = Company::query()->lockForUpdate()->findOrFail($current->id());
        $pack = CountryPack::load($company->country_code);

        $data = $request->validate([
            // The version the client read (from GET company); a save is refused (409) if someone else saved in between.
            'version' => ['required', 'string'],
            'legal_name' => ['sometimes', 'required', 'string', 'max:200'],
            'trading_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'legal_form' => ['sometimes', 'nullable', Rule::in(self::LEGAL_FORMS)],
            'industry' => ['sometimes', 'nullable', Rule::in(array_keys(CountryPack::industries()))],
            'tin' => ['sometimes', 'nullable', 'string', 'max:30'],
            'vat_registered' => ['sometimes', 'boolean'],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^\\+?[0-9 ()-]{6,30}$/'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'website' => ['sometimes', 'nullable', 'url', 'max:200'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:200'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:200'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'region' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'timezone' => ['sometimes', 'required', 'timezone'],
            'accounting_basis' => ['sometimes', 'required', Rule::in(['accrual', 'cash'])],
            'base_currency' => ['sometimes', 'required', Rule::in(Currency::all())],
            'calendar' => ['sometimes', 'required', Rule::in(array_values(array_unique(['gregorian', ...$pack->calendars])))],
            'fiscal_year_start_month' => ['sometimes', 'required', 'integer', 'between:1,12'],
            'conversion_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:' . app(CurrentCompany::class)->today()],
            // The one optional approval step: above these amounts (base currency), someone other than the creator approves.
            'approval' => ['sometimes', 'array:' . implode(',', self::APPROVAL_LIMITS)],
            'approval.*' => ['nullable', 'decimal:0,2', 'gt:0'],
            // Payment reminders (048): an email to the customer so many days after an invoice falls due, while owed.
            'reminders' => ['sometimes', 'array:enabled,days'],
            'reminders.enabled' => ['required_with:reminders', 'boolean'],
            'reminders.days' => ['required_with:reminders', 'array', 'min:1', 'max:3'],
            'reminders.days.*' => ['integer', 'between:1,365', 'distinct'],
        ]);

        if ($data['version'] !== $this->version($company)) {
            return response()->json(['message' => 'Someone else changed these settings after you opened them. Reload and try again.', 'type' => 'StaleSettings'], 409);
        }
        unset($data['version']);
        if (! empty($data['tin']) && ! $pack->isValidTaxId($data['tin'])) {
            throw ValidationException::withMessages(['tin' => ["The {$pack->taxIdLabel} format is not valid for this country."]]);
        }

        $before = $this->editable($company);
        if (array_key_exists('approval', $data)) {
            $limits = [...($company->settings['approval'] ?? []), ...$data['approval']];
            $company->settings = [...($company->settings ?? []), 'approval' => array_filter($limits, fn ($v) => $v !== null && $v !== '')];
            unset($data['approval']);
        }
        if (array_key_exists('reminders', $data)) {
            $days = array_map('intval', $data['reminders']['days']);
            sort($days);
            $company->settings = [...($company->settings ?? []), 'reminders' => ['enabled' => (bool) $data['reminders']['enabled'], 'days' => $days]];
            unset($data['reminders']);
        }
        $company->fill($data);
        $changes = AuditLogger::diff($before, $this->editable($company));
        if ($changes === []) {
            return response()->json($this->present($company));
        }

        $foundationChanged = array_intersect(array_keys($changes), self::FOUNDATION);
        if ($foundationChanged !== []) {
            // Waits for a posting in flight, then keeps new ones out until this commits (011_company_settings.sql).
            DB::select('select pg_advisory_xact_lock(ledger_period_lock_key(?::uuid))', [$company->getKey()]);
        }
        if ($foundationChanged !== [] && $this->booksStarted($company)) {
            throw ValidationException::withMessages(array_fill_keys(array_values($foundationChanged),
                ['Fixed once anything is posted. A company that needs another one starts new books.']));
        }

        $company->save();
        if ($foundationChanged !== []) {
            $this->reopenFirstFiscalYear($company);
        }
        $this->audit->record($company->getKey(), 'updated', 'company', $company->getKey(),
            array_map(fn (array $c) => $c['old'], $changes), array_map(fn (array $c) => $c['new'], $changes),
            userId: $request->user()->getKey());

        return response()->json($this->present($company->refresh()));
    }

    /**
     * The period lock: nothing may post on or before books_closed_until. Moving it forward closes periods (an
     * accountant's everyday work); moving it back or clearing it reopens them, for an owner or admin with a reason.
     */
    /** The close checklist for a period end (default: the end of last month), and where the books are closed now. */
    public function closeChecklist(Request $request, CurrentCompany $current, CloseChecklist $checklist): JsonResponse
    {
        $this->authorizeCompany('periods.close');
        $end = $request->validate(['period_end' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:' . $current->today()]])['period_end']
            ?? (new \DateTimeImmutable($current->today()))->modify('first day of this month')->modify('-1 day')->format('Y-m-d');
        $company = $current->get();

        return response()->json([...$checklist->run($company, $end), 'books_closed_until' => $company->books_closed_until?->format('Y-m-d'),
            'can' => ['reopen' => (bool) $current->membership()?->can('periods.reopen')]]);
    }

    public function periodLock(Request $request, CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('periods.close');

        $data = $request->validate([
            'books_closed_until' => ['present', 'nullable', 'date_format:Y-m-d', 'before_or_equal:' . app(CurrentCompany::class)->today()],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $company = Company::query()->lockForUpdate()->findOrFail($current->id());
        $old = $company->books_closed_until?->format('Y-m-d');
        $new = $data['books_closed_until'];
        if ($old === $new) {
            return response()->json(['books_closed_until' => $new]);
        }

        $reopening = $new === null || ($old !== null && $new < $old);
        if ($reopening) {
            $this->authorizeCompany('periods.reopen');
            if (blank($data['reason'] ?? null)) {
                throw ValidationException::withMessages(['reason' => ['Say why the closed period is being reopened.']]);
            }
        }

        // Closing: what the checklist said at that moment goes into the record (it informs the close, it does not block it).
        $checklist = $reopening ? null : app(CloseChecklist::class)->run($company, $new);
        DB::table('companies')->where('id', $company->getKey())->update(['books_closed_until' => $new, 'updated_at' => now()]);
        $this->audit->record($company->getKey(), $reopening ? 'period_reopened' : 'period_closed', 'company', $company->getKey(),
            ['books_closed_until' => $old], ['books_closed_until' => $new, ...($checklist ? ['checklist_warnings' => array_values(array_map(
                fn (array $i) => $i['key'], array_filter($checklist['items'], fn (array $i) => $i['status'] !== 'ok')))] : [])],
            $data['reason'] ?? null, $request->user()->getKey());

        return response()->json(['books_closed_until' => $new]);
    }

    /** @return array<string, mixed> */
    private function present(Company $company): array
    {
        $pack = CountryPack::load($company->country_code);
        $booksStarted = $this->booksStarted($company);
        $membership = app(CurrentCompany::class)->membership();

        return [
            'id' => $company->getKey(),
            'version' => $this->version($company),
            'country_code' => $company->country_code,
            'framework' => $company->framework,
            'status' => $company->status,
            ...$this->editable($company),
            'books_closed_until' => $company->books_closed_until?->format('Y-m-d'),
            'books_started' => $booksStarted,
            'locked_fields' => $booksStarted ? self::FOUNDATION : [],
            'fiscal_years' => DB::table('fiscal_years')->where('company_id', $company->getKey())
                ->orderBy('starts_on')->get(['starts_on', 'ends_on', 'status']),
            // What the signed-in member may do here, so the screen offers only that; the API checks again on every call.
            'can' => [
                'manage' => (bool) $membership?->can('company.manage'),
                'close_periods' => (bool) $membership?->can('periods.close'),
                'reopen_periods' => (bool) $membership?->can('periods.reopen'),
                'enter_rates' => (bool) $membership?->can('accounting.rates'),
                'manage_dimensions' => (bool) $membership?->can('accounting.dimensions'),
            ],
            'options' => [
                'tax_id_label' => $pack->taxIdLabel,
                'calendars' => array_values(array_unique(['gregorian', ...$pack->calendars])),
                'legal_forms' => self::LEGAL_FORMS,
            ],
        ];
    }

    /** @return array<string, mixed> the fields this controller may change, as stored */
    private function editable(Company $company): array
    {
        $fields = [];
        foreach ([...self::PROFILE, ...self::FOUNDATION] as $field) {
            $value = $company->getAttribute($field);
            $fields[$field] = match (true) {
                $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                $field === 'fiscal_year_start_month' => $value === null ? null : (int) $value,
                $field === 'vat_registered' => (bool) $value,
                default => $value,
            };
        }

        $fields['approval'] = array_intersect_key($company->settings['approval'] ?? [], array_flip(self::APPROVAL_LIMITS));
        $fields['reminders'] = \App\Domain\Sales\InvoiceReminders::settingsOf($company);

        return $fields;
    }

    private function version(Company $company): string
    {
        return hash('sha256', json_encode([$company->updated_at?->format('Y-m-d\TH:i:s.uP'), $this->editable($company)], JSON_THROW_ON_ERROR));
    }

    private function booksStarted(Company $company): bool
    {
        return DB::table('journal_entries')->where('company_id', $company->getKey())->exists();
    }

    /** Nothing is posted yet, so the first fiscal year simply follows the new settings. */
    private function reopenFirstFiscalYear(Company $company): void
    {
        $fiscal = new FiscalCalendar($company->calendar, (int) $company->fiscal_year_start_month);
        $year = $fiscal->yearContaining(new DateTimeImmutable($company->conversion_date?->format('Y-m-d') ?? 'today'));

        DB::table('fiscal_years')->where('company_id', $company->getKey())->delete();
        DB::table('fiscal_years')->insert([
            'id' => (string) Str::uuid7(),
            'company_id' => $company->getKey(),
            'starts_on' => $year['start']->format('Y-m-d'),
            'ends_on' => $year['end']->format('Y-m-d'),
        ]);
    }
}

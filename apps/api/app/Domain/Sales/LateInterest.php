<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Models\Account;
use App\Domain\Sales\Actions\SaveInvoiceDraft;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 2: interest on late payment (079). At the annual rate the company set, simple interest on what an overdue
 * invoice still owes, for the days from its due date (or the end of the last charge) to a date, is charged on a new
 * draft invoice to the same customer, in the same currency, to the income account the company chose, with no tax.
 * It is approved like any other invoice; voided, its days may be charged again.
 */
final class LateInterest
{
    public function __construct(private readonly CurrentCompany $current, private readonly SaveInvoiceDraft $drafts, private readonly AuditLogger $audit)
    {
    }

    /** @return array{rate: ?string, account_id: ?string} the company's late-payment interest settings */
    public static function settingsOf(Company $company): array
    {
        $s = $company->settings['late_interest'] ?? [];

        return ['rate' => isset($s['rate']) ? (string) $s['rate'] : null, 'account_id' => isset($s['account_id']) ? (string) $s['account_id'] : null];
    }

    /**
     * What charging interest on $invoice through $through would make, and what was charged before.
     *
     * @return array<string, mixed>
     */
    public function preview(Document $invoice, string $through): array
    {
        $settings = self::settingsOf($this->current->get());
        $from = $this->chargedThrough($invoice) ?? $invoice->due_date?->format('Y-m-d');
        $owed = (string) $invoice->amount_due;
        $days = $from !== null && $through > $from ? (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($through))->days : 0;
        $amount = $settings['rate'] !== null && in_array($invoice->status, ['posted', 'part_paid'], true) ? self::interest($owed, $settings['rate'], $days) : '0.00';

        return ['rate' => $settings['rate'], 'account_id' => $settings['account_id'], 'owed' => $owed, 'currency' => $invoice->currency,
            'from' => $from, 'through' => $through, 'days' => $days, 'amount' => $amount, 'charged' => $this->charged($invoice)];
    }

    /** Makes the draft interest invoice for $invoice through $through, as $userId. */
    public function charge(Document $invoice, string $through, string $userId): Document
    {
        $company = $this->current->get();
        $settings = self::settingsOf($company);
        if ($settings['rate'] === null || $settings['account_id'] === null) {
            throw ValidationException::withMessages(['rate' => ['Set the interest rate and its income account in the company settings first.']]);
        }

        return DB::transaction(function () use ($invoice, $through, $userId, $company, $settings) {
            $invoice = Document::query()->lockForUpdate()->findOrFail($invoice->getKey());
            if ($invoice->type !== 'invoice' || ! in_array($invoice->status, ['posted', 'part_paid'], true) || $invoice->due_date === null) {
                throw ValidationException::withMessages(['through' => ['Interest is charged on a posted invoice that still owes something and has a due date.']]);
            }
            if ($through > $this->current->today()) {
                throw ValidationException::withMessages(['through' => ['Interest is charged up to today at most.']]);
            }
            $p = $this->preview($invoice, $through);
            if (bccomp((string) $p['amount'], '0', 2) <= 0) {
                throw ValidationException::withMessages(['through' => ["Nothing to charge: {$invoice->number} is not overdue for any day after {$p['from']}."]]);
            }
            if (! Account::query()->whereKey($settings['account_id'])->where(['type' => 'income', 'is_active' => true])->exists()) {
                throw ValidationException::withMessages(['rate' => ['The income account set for interest is no longer in use: choose another in the company settings.']]);
            }

            $today = $this->current->today();
            $draft = $this->drafts->handle([
                'contact_id' => $invoice->contact_id, 'issue_date' => $today,
                'due_date' => PaymentTerms::forContact($invoice->contact_id, $today, (string) $this->current->get()->calendar),   // 109
                'currency' => $invoice->currency, 'prices_include_tax' => false,
                'notes' => "Interest on late payment of {$invoice->number}.",
                'lines' => [['description' => mb_substr("Interest on {$invoice->number}: {$p['days']} days from {$p['from']} to {$through} at {$p['rate']}% a year on {$p['owed']} owed", 0, 500),
                    'quantity' => '1', 'unit_price' => $p['amount'], 'account_id' => $settings['account_id']]],
            ], null, $userId);
            $draft->forceFill(['interest_on_document_id' => $invoice->getKey(), 'interest_from' => $p['from'], 'interest_through' => $through])->save();

            $this->audit->record($company->getKey(), 'late_interest_charged', 'invoice', $invoice->getKey(), null,
                ['interest_invoice_id' => $draft->getKey(), 'from' => $p['from'], 'through' => $through, 'days' => $p['days'], 'rate' => $p['rate'], 'amount' => $p['amount']],
                userId: $userId);

            return $draft->refresh();
        });
    }

    /** Simple interest: $owed x $rate% x $days / 365, rounded half up to the cent. */
    public static function interest(string $owed, string $rate, int $days): string
    {
        if ($days <= 0 || bccomp($owed, '0', 2) <= 0) {
            return '0.00';
        }

        return bcadd(bcdiv(bcmul(bcmul($owed, $rate, 8), (string) $days, 8), '36500', 8), '0.005', 2);
    }

    /** The last day already charged for (live interest invoices only), or null. */
    private function chargedThrough(Document $invoice): ?string
    {
        $d = Document::query()->where('interest_on_document_id', $invoice->getKey())->where('status', '<>', 'void')->max('interest_through');

        return $d === null ? null : substr((string) $d, 0, 10);
    }

    /** @return list<array<string, mixed>> */
    private function charged(Document $invoice): array
    {
        return Document::query()->where('interest_on_document_id', $invoice->getKey())->orderBy('interest_from')
            ->get(['id', 'number', 'status', 'interest_from', 'interest_through', 'total'])
            ->map(fn (Document $d) => ['id' => $d->getKey(), 'number' => $d->number, 'status' => $d->status,
                'from' => substr((string) $d->getRawOriginal('interest_from'), 0, 10), 'through' => substr((string) $d->getRawOriginal('interest_through'), 0, 10), 'total' => (string) $d->total])
            ->all();
    }
}

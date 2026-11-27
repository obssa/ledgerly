<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\RecurringJournals;
use App\Domain\Sales\Actions\ApproveInvoice;
use App\Domain\Sales\Actions\SaveInvoiceDraft;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Membership;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 2: recurring invoices (077). Each time a template comes due it makes an ordinary invoice from its lines, dated
 * that day and due by the customer's terms, left as a draft or posted, as the person who set it up. It catches up on
 * the dates it missed (up to a limit), stops after its end date, and pauses with the reason when an invoice cannot be
 * made or posted. Runs inside the company's tenant transaction.
 */
final class RecurringInvoices
{
    public const FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'yearly'];

    public const CATCH_UP = 24;

    public function __construct(
        private readonly SaveInvoiceDraft $drafts,
        private readonly ApproveInvoice $approve,
        private readonly AuditLogger $audit,
    ) {
    }

    /** The date after $on: a week later, or a month, quarter or year (month-ends stay month-ends, as for journals). */
    public static function advance(string $on, string $frequency): string
    {
        return $frequency === 'weekly' ? (new DateTimeImmutable($on))->modify('+7 days')->format('Y-m-d') : RecurringJournals::advance($on, $frequency);
    }

    /**
     * Makes what has come due on the template by $today, as $userId.
     *
     * @return array{made: list<string>, error: ?string} the invoices made, and why the template paused if it did
     */
    public function makeDue(Company $company, string $templateId, string $userId, string $today): array
    {
        $t = DB::table('recurring_invoices')->where(['company_id' => $company->getKey(), 'id' => $templateId])->lockForUpdate()->first();
        if ($t === null || ! $t->is_active || (string) $t->next_on > $today) {
            return ['made' => [], 'error' => null];
        }
        $lines = json_decode((string) $t->lines, true, 512, JSON_THROW_ON_ERROR);
        $termId = DB::table('contacts')->where('id', $t->contact_id)->value('payment_terms_id');
        $next = (string) $t->next_on;
        $made = [];
        $error = $this->cannotMake($company, $t, $userId);

        while ($error === null && $next <= $today && count($made) < self::CATCH_UP && ($t->ends_on === null || $next <= (string) $t->ends_on)) {
            try {
                $invoice = DB::transaction(function () use ($t, $lines, $next, $termId, $company, $userId) {
                    $draft = $this->drafts->handle([
                        'contact_id' => $t->contact_id, 'issue_date' => $next,
                        'due_date' => PaymentTerms::forContact($t->contact_id, $next, (string) $company->calendar),   // 109: by the customer's term
                        'payment_terms_id' => $termId,
                        'currency' => $t->currency, 'prices_include_tax' => (bool) $t->prices_include_tax, 'warehouse_id' => $t->warehouse_id,
                        'notes' => $t->notes, 'lines' => $lines,
                    ], null, $userId);
                    $draft->forceFill(['recurring_invoice_id' => $t->id])->save();

                    return $t->post ? $this->approve->handle($draft->refresh(), $userId) : $draft->refresh();
                });
            } catch (ValidationException $e) {
                $error = collect($e->errors())->flatten()->first() ?? 'The invoice no longer checks out.';
                break;
            } catch (LedgerException $e) {
                $error = $e->getMessage();
                break;
            } catch (QueryException $e) {
                if ($e->getCode() !== '23514') {
                    throw $e;
                }
                $error = preg_match('/ERROR:\s+(.+?)(?:\s+CONTEXT:|\s+\(Connection:|\s+\(SQL:|$)/s', $e->getMessage(), $m) ? trim($m[1]) : 'The books refused this invoice.';
                break;
            }
            $made[] = (string) $invoice->getKey();
            $this->audit->record($company->getKey(), 'recurring_invoice_made', 'recurring_invoice', $t->id, null,
                ['date' => $next, 'invoice_id' => $invoice->getKey(), 'number' => $invoice->number, 'status' => $invoice->status, 'total' => (string) $invoice->total],
                userId: $userId);
            $next = self::advance($next, (string) $t->frequency);
        }

        $ended = $t->ends_on !== null && $next > (string) $t->ends_on;
        // An ended template keeps the date after its end: resuming it would otherwise make its last invoice again.
        $update = ['next_on' => $next, 'is_active' => $error === null && ! $ended,
            'last_error' => $error === null ? null : mb_substr($error, 0, 500)];
        if ($made !== []) {
            $update['made_count'] = $t->made_count + count($made);
            $update['last_made_on'] = Document::query()->whereKey(end($made))->value('issue_date');
        }
        DB::table('recurring_invoices')->where(['company_id' => $company->getKey(), 'id' => $t->id])->update($update);
        if ($error !== null) {
            $this->audit->record($company->getKey(), 'recurring_invoice_paused', 'recurring_invoice', $t->id, ['is_active' => true],
                ['is_active' => false, 'next_on' => $next], $error, userId: $userId);
        }

        return ['made' => $made, 'error' => $error];
    }

    /** Why the template cannot make its invoices as $userId at all, or null. */
    private function cannotMake(Company $company, object $t, string $userId): ?string
    {
        $membership = Membership::query()->where(['company_id' => $company->getKey(), 'user_id' => $userId, 'status' => 'active'])->first();
        if ($membership === null || ! $membership->can('invoices.create')) {
            return 'The person it makes invoices as can no longer write invoices: resume it as someone who can.';
        }
        if ($t->post && ! $membership->can('invoices.approve')) {
            return 'The person it makes invoices as can no longer post them: resume it as someone who can, or make drafts instead.';
        }

        return null;
    }
}

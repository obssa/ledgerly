<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Audit\AuditLogger;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Models\Company;
use App\Notifications\InvoiceReminder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Module 2, collections: an email to the customer about an invoice still owed (048). Sent by hand, or by the
 * scheduler so many days after the due date (companies.settings.reminders). Each one is recorded and audited.
 */
final class InvoiceReminders
{
    /** A reminder by hand waits this long after the last one for the same invoice. */
    public const MANUAL_GAP_HOURS = 24;

    public const DEFAULT_DAYS = [7, 30];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /** @return array{enabled: bool, days: list<int>} */
    public static function settingsOf(Company $company): array
    {
        $r = $company->settings['reminders'] ?? [];
        $days = array_values(array_filter(array_map('intval', (array) ($r['days'] ?? self::DEFAULT_DAYS)), fn ($d) => $d >= 1 && $d <= 365));

        return ['enabled' => (bool) ($r['enabled'] ?? false), 'days' => $days === [] ? self::DEFAULT_DAYS : $days];
    }

    /**
     * Sends a reminder for the invoice, inside the company's context. The record and the audit entry are written in
     * the same transaction as the email is handed over, so a reminder that could not be sent leaves no record.
     *
     * @param 'manual'|'scheduled' $kind
     */
    public function send(Company $company, Document $invoice, string $kind, ?int $step, ?string $userId): void
    {
        if ($invoice->type !== 'invoice' || ! in_array($invoice->status, ['posted', 'part_paid'], true) || bccomp((string) $invoice->amount_due, '0', 2) <= 0) {
            throw ValidationException::withMessages(['invoice' => ['Only a posted invoice with something still owed gets a reminder.']]);
        }
        $email = trim((string) DB::table('contacts')->where(['id' => $invoice->contact_id, 'company_id' => $company->getKey()])->value('email'));
        $customer = (string) DB::table('contacts')->where(['id' => $invoice->contact_id, 'company_id' => $company->getKey()])->value('name');
        if ($email === '') {
            throw ValidationException::withMessages(['invoice' => ['The customer has no email address: add one to send a reminder.']]);
        }
        if ($kind === 'manual' && DB::table('invoice_reminders')->where('document_id', $invoice->getKey())->where('sent_at', '>', now()->subHours(self::MANUAL_GAP_HOURS))->exists()) {
            throw ValidationException::withMessages(['invoice' => ['A reminder for this invoice went out in the last 24 hours.']]);
        }

        DB::transaction(function () use ($company, $invoice, $kind, $step, $userId, $email, $customer) {
            DB::table('invoice_reminders')->insert([
                'company_id' => $company->getKey(), 'document_id' => $invoice->getKey(), 'kind' => $kind, 'step' => $step,
                'sent_to' => $email, 'sent_by' => $kind === 'manual' ? $userId : null,
            ]);
            $this->audit->record($company->getKey(), 'invoice_reminder_sent', 'invoice', $invoice->getKey(), null,
                ['kind' => $kind, 'step' => $step, 'sent_to' => $email, 'amount_due' => (string) $invoice->amount_due], userId: $userId);

            Notification::route('mail', [$email => $customer])->notify(new InvoiceReminder(
                company: (string) ($company->trading_name ?: $company->legal_name),
                replyTo: $company->email ? (string) $company->email : null,
                customer: $customer,
                number: (string) $invoice->number,
                currency: (string) $invoice->currency,
                amountDue: number_format((float) $invoice->amount_due, 2),
                dueDate: \App\Support\DateFormat::format($invoice->due_date, $company),
            ));
        });
    }

    /** @return list<array{kind: string, step: int|null, sent_to: string, sent_at: string}> newest first */
    public function sentFor(string $documentId): array
    {
        return DB::table('invoice_reminders')->where('document_id', $documentId)->orderByDesc('sent_at')->orderByDesc('id')->limit(20)
            ->get(['kind', 'step', 'sent_to', 'sent_at'])
            ->map(fn ($r) => ['kind' => $r->kind, 'step' => $r->step === null ? null : (int) $r->step, 'sent_to' => $r->sent_to, 'sent_at' => (string) $r->sent_at])
            ->all();
    }
}

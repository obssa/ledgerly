<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\PostingService;
use App\Domain\Sales\Models\Payment;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reverses a payment (a bounced cheque, a transfer that came back): its journal entry (and any exchange difference booked
 * when it was applied later) is reversed on the given date,
 * its allocations are removed so the invoices owe again (006 gives the amounts back), and it is marked reversed. The
 * allocations it had are kept in the audit record.
 */
final class ReversePayment
{
    public function __construct(private readonly PostingService $posting, private readonly AuditLogger $audit)
    {
    }

    public function handle(Payment $payment, string $date, string $reason, string $userId): Payment
    {
        return DB::transaction(function () use ($payment, $date, $reason, $userId) {
            $payment = Payment::query()->with('allocations.document:id,number')->lockForUpdate()->findOrFail($payment->getKey());
            if ($payment->status !== 'posted') {
                throw new LedgerException('This payment is already reversed.');
            }

            $reversal = $this->posting->reverse($payment->journal_entry_id, new DateTimeImmutable($date), $userId);
            // Exchange differences booked when the payment was applied later go with it.
            foreach (DB::table('journal_entries')->where(['source_type' => 'payment_allocation', 'source_id' => $payment->getKey(), 'status' => 'posted'])->pluck('id') as $fxEntry) {
                $this->posting->reverse($fxEntry, new DateTimeImmutable($date), $userId);
            }
            $released = $payment->allocations->map(fn ($a) => ['invoice' => $a->document?->number, 'amount' => (string) $a->amount])->all();
            DB::table('payment_allocations')->where('payment_id', $payment->getKey())->delete();
            DB::table('payments')->where('id', $payment->getKey())->update(['status' => 'reversed']);

            $this->audit->record($payment->company_id, 'payment_reversed', 'payment', $payment->getKey(),
                ['status' => 'posted', 'allocations' => $released],
                ['status' => 'reversed', 'reversal_entry' => $reversal->number, 'date' => $date],
                $reason, $userId);

            return Payment::query()->with(['allocations', 'contact:id,name'])->findOrFail($payment->getKey());
        });
    }
}

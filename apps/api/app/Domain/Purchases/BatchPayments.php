<?php

declare(strict_types=1);

namespace App\Domain\Purchases;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Money;
use App\Domain\Purchases\Actions\PayBills;
use App\Domain\Sales\Models\Contact;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Module 3: batch payments. Several suppliers' bills are paid in one run from one money account, on one date, by one
 * method: one ordinary supplier payment per supplier, settling the bills ticked (all or part of each), with the tax
 * withheld from that supplier and its receipt number where the payer gives them. All are made or none; a supplier whose
 * payment does not check out stops the run, named in the message.
 */
final class BatchPayments
{
    public const MAX_BILLS = 200;

    public function __construct(private readonly CurrentCompany $current, private readonly PayBills $pay, private readonly AuditLogger $audit)
    {
    }

    /**
     * @param array{account_id: string, payment_date: string, method: string, reference?: ?string, currency: string, fx_rate?: ?string,
     *              bills: list<array{bill_id: string, amount?: ?string}>, withholding?: list<array{contact_id: string, amount: string, certificate: string}>} $data
     * @return array{batch_id: string, payments: list<array<string, mixed>>}
     */
    public function pay(array $data, string $userId): array
    {
        $currency = $data['currency'];
        // Company-scoped: another company's bill is simply not found.
        $bills = Document::query()->where('type', 'bill')->whereIn('id', array_column($data['bills'], 'bill_id'))->get()->keyBy(fn (Document $b) => (string) $b->getKey());
        $groups = [];
        foreach ($data['bills'] as $i => $row) {
            $bill = $bills[$row['bill_id']] ?? null;
            if ($bill === null) {
                throw ValidationException::withMessages(["bills.{$i}.bill_id" => ['Not a bill of this company.']]);
            }
            if (! in_array($bill->status, ['posted', 'part_paid'], true) || $bill->currency !== $currency) {
                throw ValidationException::withMessages(["bills.{$i}.bill_id" => ["{$bill->label()} is not open for payment in {$currency}."]]);
            }
            $amount = isset($row['amount']) && $row['amount'] !== '' ? (string) $row['amount'] : (string) $bill->amount_due;
            $groups[(string) $bill->contact_id][] = ['bill_id' => (string) $bill->getKey(), 'amount' => $amount];
        }
        $withheld = collect($data['withholding'] ?? [])->keyBy('contact_id');
        foreach ($withheld->keys() as $contactId) {
            if (! isset($groups[$contactId])) {
                throw ValidationException::withMessages(['withholding' => ['Tax is withheld only from a supplier paid in this run.']]);
            }
        }
        $suppliers = Contact::query()->whereIn('id', array_keys($groups))->orderBy('name')->get();

        return DB::transaction(function () use ($data, $groups, $withheld, $suppliers, $currency, $userId) {
            $batchId = (string) Str::uuid7();
            $payments = [];
            foreach ($suppliers as $supplier) {
                $allocations = $groups[(string) $supplier->getKey()];
                $total = array_reduce($allocations, fn (Money $s, array $a) => $s->plus(Money::of($a['amount'], $currency)), Money::zero($currency));
                $wh = $withheld[(string) $supplier->getKey()] ?? null;
                $withholding = Money::of($wh['amount'] ?? '0', $currency);
                $cash = $total->minus($withholding);
                if ($cash->isZero() || $cash->isNegative()) {
                    throw ValidationException::withMessages(['withholding' => ["{$supplier->name}: the tax withheld leaves nothing to pay. Nothing was paid."]]);
                }
                try {
                    $payment = $this->pay->handle([
                        'contact_id' => (string) $supplier->getKey(), 'account_id' => $data['account_id'], 'payment_date' => $data['payment_date'],
                        'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'currency' => $currency, 'fx_rate' => $data['fx_rate'] ?? null,
                        'amount' => $cash->toDecimalString(), 'withholding' => $withholding->isZero() ? null : $withholding->toDecimalString(),
                        'withholding_certificate' => $wh['certificate'] ?? null, 'allocations' => $allocations,
                    ], $userId);
                } catch (ValidationException $e) {
                    $why = collect($e->errors())->flatten()->first() ?? 'the payment does not check out.';
                    throw ValidationException::withMessages(['bills' => ["{$supplier->name}: {$why} Nothing was paid."]]);
                }
                $payments[] = ['id' => (string) $payment->getKey(), 'number' => $payment->number ?? null, 'supplier' => (string) $supplier->name,
                    'amount' => $cash->toDecimalString(), 'withholding' => $withholding->toDecimalString(), 'bills' => count($allocations)];
            }
            $this->audit->record($this->current->id(), 'batch_paid', 'payment_batch', $batchId, null,
                ['account_id' => $data['account_id'], 'date' => $data['payment_date'], 'method' => $data['method'], 'currency' => $currency, 'payments' => $payments],
                userId: $userId);

            return ['batch_id' => $batchId, 'payments' => $payments];
        });
    }
}

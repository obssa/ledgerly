<?php

declare(strict_types=1);

namespace App\Domain\Purchases;

use App\Domain\Audit\AuditLogger;
use App\Domain\Sales\Actions\InvoiceWorkflow;
use App\Domain\Sales\Actions\SaveInvoiceDraft;
use App\Domain\Sales\Models\Contact;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Module 3: employee expense claims (083). What a member of staff paid for the company out of their own pocket becomes
 * an ordinary bill payable to them: the company's supplier record for that member (made the first time they claim),
 * each receipt a line at the amount paid, tax included. They submit it; someone else approves it with the usual bill
 * approval (never the claimant), and it is paid like any bill.
 */
final class ExpenseClaims
{
    public function __construct(
        private readonly CurrentCompany $current,
        private readonly SaveInvoiceDraft $drafts,
        private readonly InvoiceWorkflow $workflow,
        private readonly AuditLogger $audit,
    ) {
    }

    /** The company's supplier record for this member, made the first time they claim. */
    public function payeeFor(User $user): Contact
    {
        $payee = Contact::query()->where('employee_user_id', $user->getKey())->first();
        if ($payee !== null) {
            return $payee;
        }
        $payee = new Contact(['name' => mb_substr((string) $user->name, 0, 200), 'is_vendor' => true, 'is_customer' => false, 'email' => $user->email]);
        $payee->forceFill(['id' => (string) Str::uuid7(), 'company_id' => $this->current->id(), 'employee_user_id' => $user->getKey()])->save();
        $this->audit->record($this->current->id(), 'staff_payee_created', 'contact', $payee->getKey(), null, ['name' => $payee->name], userId: $user->getKey());

        return $payee;
    }

    /**
     * @param array{issue_date: string, currency: string, notes?: ?string, lines: list<array{description: string, amount: string, account_id: string, tax_rate_id?: ?string, project_id?: ?string}>} $data
     */
    public function claim(array $data, User $user): Document
    {
        return DB::transaction(function () use ($data, $user) {
            $payee = $this->payeeFor($user);
            $claim = $this->drafts->handle([
                'contact_id' => $payee->getKey(), 'issue_date' => $data['issue_date'], 'currency' => $data['currency'],
                'prices_include_tax' => true,   // a receipt's amount is what was paid, tax included
                'notes' => $data['notes'] ?? null,
                'lines' => array_map(fn (array $l) => array_filter([
                    'description' => $l['description'], 'quantity' => '1', 'unit_price' => (string) $l['amount'], 'account_id' => $l['account_id'],
                    'tax_rate_id' => $l['tax_rate_id'] ?? null, 'project_id' => $l['project_id'] ?? null,
                ], fn ($v) => $v !== null), $data['lines']),
            ], null, $user->getKey(), newType: 'bill');
            $claim->forceFill(['claimed_by' => $user->getKey()])->save();
            $this->audit->record($this->current->id(), 'expense_claimed', 'bill', $claim->getKey(), null,
                ['claimant' => $user->name, 'total' => (string) $claim->total, 'lines' => count($data['lines'])], userId: $user->getKey());

            return $claim->refresh();
        });
    }

    /** The claimant sends their draft claim for approval. */
    public function submit(Document $claim, User $user): Document
    {
        return $this->workflow->submitForApproval($claim, $user->getKey());
    }
}

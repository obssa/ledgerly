<?php

declare(strict_types=1);

namespace App\Domain\Purchases;

use Illuminate\Support\Facades\DB;

/**
 * Module 3: the bank payment file. The supplier payments recorded from one money account on one day by bank transfer
 * (or by mobile money), as a CSV to upload to the bank's bulk transfer: one row per payment, with the supplier's
 * account as it is on the supplier (the payout details). It moves no money: the payments are already recorded and
 * posted, and the bank carries them out. A supplier without the details is listed apart, not in the file, and one whose
 * details changed in the last 30 days is flagged, to call and confirm before uploading. Reversed payments are left out.
 * Every query is the company's own under row-level security, and no text from a request becomes SQL.
 */
final class PaymentFile
{
    public const METHODS = ['bank_transfer', 'mobile_money'];

    public const RECENT_DAYS = 30;

    /** @return array<string, mixed> */
    public function build(string $companyId, string $date, ?string $accountId, string $method): array
    {
        $payments = DB::table('payments as p')->join('contacts as c', 'c.id', '=', 'p.contact_id')->join('accounts as a', 'a.id', '=', 'p.account_id')
            ->where(['p.company_id' => $companyId, 'p.direction' => 'out', 'p.kind' => 'payment', 'p.status' => 'posted', 'p.method' => $method, 'p.payment_date' => $date])
            ->when($accountId !== null, fn ($q) => $q->where('p.account_id', $accountId))
            ->orderBy('c.name')->orderBy('p.created_at')->limit(2000)
            ->get(['p.id', 'p.reference', 'p.currency', 'p.amount', 'p.contact_id', 'c.name as supplier', 'c.payout', 'a.code as account_code', 'a.name as account']);
        $changed = DB::table('audit_log')->where(['company_id' => $companyId, 'action' => 'payout_changed', 'subject_type' => 'supplier'])
            ->whereIn('subject_id', $payments->pluck('contact_id')->unique()->values()->all())->where('created_at', '>=', now()->subDays(self::RECENT_DAYS))
            ->groupBy('subject_id')->selectRaw('subject_id, max(created_at)::date::text AS changed_on')->pluck('changed_on', 'subject_id');

        $rows = [];
        $missing = [];
        $totals = [];
        foreach ($payments as $p) {
            $payout = json_decode((string) $p->payout, true) ?: [];
            [$institution, $number] = $method === 'mobile_money'
                ? [$payout['mobile_money_provider'] ?? null, $payout['mobile_money_number'] ?? null]
                : [$payout['bank_name'] ?? null, $payout['account_number'] ?? null];
            $line = ['payment_id' => $p->id, 'supplier' => $p->supplier, 'contact_id' => $p->contact_id, 'amount' => (string) $p->amount, 'currency' => $p->currency,
                'reference' => $p->reference, 'from' => trim("{$p->account_code} {$p->account}")];
            if (trim((string) $institution) === '' || trim((string) $number) === '') {
                $missing[] = [...$line, 'needs' => $method === 'mobile_money' ? 'the mobile money provider and number' : 'the bank and account number'];

                continue;
            }
            $rows[] = [...$line, 'institution' => trim((string) $institution), 'account_name' => trim((string) ($payout['account_name'] ?? '')) ?: $p->supplier,
                'account_number' => trim((string) $number), 'details_changed_on' => $changed[$p->contact_id] ?? null];
            $totals[$p->currency] = bcadd($totals[$p->currency] ?? '0', (string) $p->amount, 2);
        }

        $header = $method === 'mobile_money'
            ? ['Beneficiary', 'Provider', 'Mobile number', 'Amount', 'Currency', 'Reference', 'Payment']
            : ['Beneficiary', 'Bank', 'Account number', 'Amount', 'Currency', 'Reference', 'Payment'];
        $csv = self::line($header);
        foreach ($rows as $r) {
            $csv .= self::line([$r['account_name'], $r['institution'], $r['account_number'], $r['amount'], $r['currency'], $r['reference'] ?? $r['supplier'], $r['payment_id']]);
        }
        ksort($totals);

        return ['date' => $date, 'account_id' => $accountId, 'method' => $method, 'rows' => $rows, 'missing' => $missing,
            'recently_changed' => count(array_filter($rows, fn ($r) => $r['details_changed_on'] !== null)), 'totals' => $totals,
            'filename' => ($method === 'mobile_money' ? 'mobile-money' : 'bank') . "-payments-{$date}.csv", 'csv' => $csv];
    }

    /** @param list<mixed> $cells */
    private static function line(array $cells): string
    {
        return implode(',', array_map(fn ($cell) => self::quote(self::cell($cell)), $cells)) . "\r\n";
    }

    /** As CompanyExport: a cell a spreadsheet would run as a formula gets an apostrophe; numbers stay as they are. */
    private static function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if (preg_match('/^-?\d+(\.\d+)?$/', $text) === 1) {
            return $text;
        }

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'{$text}" : $text;
    }

    private static function quote(string $text): string
    {
        return preg_match('/[",\r\n]/', $text) === 1 ? '"' . str_replace('"', '""', $text) . '"' : $text;
    }
}

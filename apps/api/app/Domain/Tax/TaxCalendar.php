<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\CountryPack;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The filing calendar (Module 7): for each return the country pack sets a due date for (rules.filing), the company's
 * monthly periods of the past year and the current one, each with its due date and where it stands: filed and paid,
 * filed, overdue, due soon (within ten days), due, or still open. The due dates are the pack's; a tax adviser confirms
 * them before go-live, like the rates.
 */
final class TaxCalendar
{
    public const DUE_SOON_DAYS = 10;

    /** @return array{kinds: array<string, array{note: ?string, periods: list<array<string, mixed>>}>, overdue: int, due_soon: int} */
    public function for(Company $company, string $today): array
    {
        $rules = CountryPack::load($company->country_code)->rules['filing'] ?? [];
        $first = (new DateTimeImmutable($today))->modify('first day of this month')->modify('-12 months');
        $conversion = $company->conversion_date ? DateTimeImmutable::createFromInterface($company->conversion_date)->modify('first day of this month') : null;
        if ($conversion !== null && $conversion > $first) {
            $first = $conversion;
        }
        $returns = DB::table('tax_returns')->where('company_id', $company->getKey())->where('period_end', '>=', $first->format('Y-m-d'))
            ->get(['id', 'kind', 'period_start', 'period_end', 'filed_on', 'settled_on']);

        $kinds = [];
        $overdue = 0;
        $dueSoon = 0;
        foreach (TaxReturns::KINDS as $kind) {
            $rule = $rules[$kind] ?? null;
            if (! is_array($rule) || ($rule['frequency'] ?? null) !== 'monthly') {
                continue;
            }
            $periods = [];
            for ($start = $first; $start->format('Y-m-d') <= $today; $start = $start->modify('+1 month')) {
                $end = $start->modify('last day of this month');
                $due = $this->dueOn($end, $rule);
                $from = $start->format('Y-m-d');
                $to = $end->format('Y-m-d');
                $filed = $returns->first(fn ($r) => $r->kind === $kind && $r->period_start <= $to && $r->period_end >= $from);
                $status = match (true) {
                    $filed !== null && $filed->settled_on !== null => 'paid',
                    $filed !== null => 'filed',
                    $to >= $today => 'open',
                    $due < $today => 'overdue',
                    (new DateTimeImmutable($today))->diff(new DateTimeImmutable($due))->days <= self::DUE_SOON_DAYS => 'due_soon',
                    default => 'due',
                };
                $overdue += $status === 'overdue' ? 1 : 0;
                $dueSoon += $status === 'due_soon' ? 1 : 0;
                $periods[] = ['period_start' => $from, 'period_end' => $to, 'due_on' => $due, 'status' => $status,
                    'return' => $filed ? ['id' => $filed->id, 'filed_on' => $filed->filed_on, 'settled_on' => $filed->settled_on] : null];
            }
            $kinds[$kind] = ['note' => $rule['note'] ?? null, 'periods' => array_reverse($periods)];
        }

        return ['kinds' => $kinds, 'overdue' => $overdue, 'due_soon' => $dueSoon];
    }

    /** @param array<string, mixed> $rule */
    private function dueOn(DateTimeImmutable $end, array $rule): string
    {
        if (isset($rule['due_day_of_next_month'])) {
            $next = $end->modify('first day of next month');
            $day = min((int) $rule['due_day_of_next_month'], (int) $next->format('t'));

            return $next->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
        }

        return $end->modify('+' . (int) ($rule['due_days_after_period'] ?? 30) . ' days')->format('Y-m-d');
    }
}

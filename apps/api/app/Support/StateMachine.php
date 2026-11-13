<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The allowed status changes of every record that has a status (config/state_machines.php).
 *
 * Actions call assert() before they change a status, so the user gets a clear message. The database holds
 * the same list (database/schema/006_state_machines.sql) and refuses an illegal change even if an action
 * forgets to ask; StateMachineTest keeps the two lists identical.
 */
final class StateMachine
{
    /** @param array<string, array<string, list<string>>> $machines machine => from => [to, ...]; '' = may be created as */
    public function __construct(private readonly array $machines)
    {
    }

    public static function fromConfig(): self
    {
        return new self(config('state_machines'));
    }

    /** The machine a document type follows. */
    public static function forDocumentType(string $type): string
    {
        return in_array($type, ['quote', 'sales_order', 'purchase_order'], true) ? 'quote' : 'invoice';
    }

    public function can(string $machine, string $from, string $to): bool
    {
        return in_array($to, $this->machines[$machine][$from] ?? [], true);
    }

    public function assert(string $machine, string $from, string $to, string $subject = ''): void
    {
        if (! $this->can($machine, $from, $to)) {
            $what = $subject !== '' ? $subject : ucfirst(str_replace('_', ' ', $machine));
            $from = $from === '' ? 'new' : str_replace('_', ' ', $from);
            throw new IllegalTransition("{$what} cannot move from {$from} to " . str_replace('_', ' ', $to) . '.');
        }
    }

    /** @return list<string> */
    public function states(string $machine): array
    {
        $states = [];
        foreach ($this->machines[$machine] ?? [] as $from => $targets) {
            array_push($states, $from, ...$targets);
        }

        return array_values(array_unique(array_filter($states, fn (string $s) => $s !== '')));
    }

    /** States with no way out: nothing further can happen to the record. */
    public function isFinal(string $machine, string $state): bool
    {
        return ($this->machines[$machine][$state] ?? []) === [];
    }

    /** @return list<array{0: string, 1: string, 2: string}> every (machine, from, to), sorted */
    public function transitions(): array
    {
        $all = [];
        foreach ($this->machines as $machine => $moves) {
            foreach ($moves as $from => $targets) {
                foreach ($targets as $to) {
                    $all[] = [$machine, (string) $from, $to];
                }
            }
        }
        sort($all);

        return $all;
    }
}

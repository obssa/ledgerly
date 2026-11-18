<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\IllegalTransition;
use App\Support\StateMachine;
use PHPUnit\Framework\TestCase;

final class StateMachineTest extends TestCase
{
    private function machine(): StateMachine
    {
        return new StateMachine(require dirname(__DIR__, 2) . '/config/state_machines.php');
    }

    public function test_the_api_and_the_database_hold_the_same_transitions(): void
    {
        $schema = dirname(__DIR__, 2) . '/database/schema';
        $sql = (string) file_get_contents($schema . '/006_state_machines.sql');
        // Later files add transitions with their own INSERT INTO state_transitions (056: a confirmed order is cancelled).
        foreach (glob($schema . '/0*.sql') as $file) {
            if (basename($file) !== '006_state_machines.sql' && preg_match_all('/INSERT INTO state_transitions\b[^;]*;/', (string) file_get_contents($file), $more)) {
                $sql .= "\n" . implode("\n", $more[0]);
            }
        }
        preg_match_all("/\\('(\\w+)',\\s*'(\\w*)',\\s*'(\\w+)',\\s*'\\w+'\\)/", $sql, $rows, PREG_SET_ORDER);
        $database = array_map(fn (array $r) => [$r[1], $r[2], $r[3]], $rows);
        sort($database);

        $this->assertTrue(count($database) > 60, 'transitions were not parsed from the SQL file');
        $this->assertSame($database, $this->machine()->transitions());
    }

    public function test_invoice_follows_its_lifecycle(): void
    {
        $sm = $this->machine();

        foreach ([['', 'draft'], ['draft', 'awaiting_approval'], ['awaiting_approval', 'posted'], ['draft', 'posted'],
                  ['posted', 'part_paid'], ['part_paid', 'paid'], ['paid', 'part_paid'], ['posted', 'void']] as [$from, $to]) {
            $this->assertTrue($sm->can('invoice', $from, $to), "{$from} -> {$to} should be allowed");
        }
    }

    public function test_every_move_that_is_not_listed_is_refused(): void
    {
        $sm = $this->machine();

        foreach (['subscription', 'company', 'membership', 'fiscal_year', 'journal_entry', 'invoice', 'quote', 'payment', 'einvoice', 'bank_transaction'] as $machine) {
            $states = $sm->states($machine);
            $allowed = array_filter($sm->transitions(), fn (array $t) => $t[0] === $machine);
            $count = 0;
            foreach (['', ...$states] as $from) {
                foreach ($states as $to) {
                    $count += $sm->can($machine, $from, $to) ? 1 : 0;
                }
            }
            $this->assertSame(count($allowed), $count, "{$machine}: can() allows something that is not listed");
        }

        $this->assertFalse($sm->can('invoice', 'paid', 'void'), 'a paid invoice cannot be voided');
        $this->assertFalse($sm->can('invoice', 'part_paid', 'void'), 'a part-paid invoice cannot be voided');
        $this->assertFalse($sm->can('invoice', 'void', 'draft'), 'void is final');
        $this->assertFalse($sm->can('invoice', '', 'posted'), 'an invoice starts as a draft');
        $this->assertFalse($sm->can('einvoice', 'cleared', 'queued'), 'cleared is final');
        $this->assertFalse($sm->can('journal_entry', 'reversed', 'posted'), 'a reversal cannot be undone');
        $this->assertFalse($sm->can('subscription', 'cancelled', 'active'), 'cancelled is final');
        $this->assertFalse($sm->can('quote', 'sent', 'posted'), 'quotes never post');
    }

    public function test_final_states(): void
    {
        $sm = $this->machine();

        $this->assertTrue($sm->isFinal('invoice', 'void'));
        $this->assertTrue($sm->isFinal('einvoice', 'cleared'));
        $this->assertTrue($sm->isFinal('subscription', 'cancelled'));
        $this->assertFalse($sm->isFinal('invoice', 'paid'), 'a payment can still be reversed');
    }

    public function test_assert_explains_the_refusal(): void
    {
        $this->assertSame('invoice', StateMachine::forDocumentType('credit_note'));
        $this->assertSame('quote', StateMachine::forDocumentType('sales_order'));

        try {
            $this->machine()->assert('invoice', 'part_paid', 'void', 'INV-0001');
            $this->fail('expected IllegalTransition');
        } catch (IllegalTransition $e) {
            $this->assertSame('INV-0001 cannot move from part paid to void.', $e->getMessage());
        }
    }
}

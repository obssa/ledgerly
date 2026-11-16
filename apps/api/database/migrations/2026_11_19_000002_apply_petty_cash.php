<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Applies database/schema/030_petty_cash.sql (Module 5): petty cash floats and cash counts. Cash counts post their
 * differences to "Cash over and short", a system account new companies get from the chart template; companies set up
 * before it existed get it here, with the first free code from 6980.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('030_petty_cash.sql'));

        foreach (DB::table('companies')->pluck('id') as $company) {
            if (DB::table('accounts')->where(['company_id' => $company, 'system_key' => 'cash_over_short'])->exists()) {
                continue;
            }
            $used = DB::table('accounts')->where('company_id', $company)->pluck('code')->map(fn ($c) => (string) $c)->all();
            $code = collect([...range(6980, 6989), ...range(6970, 6979)])->map(fn ($n) => (string) $n)->first(fn ($c) => ! in_array($c, $used, true));
            if ($code === null) {
                throw new RuntimeException("Company {$company} has no free code for Cash over and short between 6970 and 6989.");
            }
            DB::table('accounts')->insert([
                'company_id' => $company, 'code' => $code, 'name' => 'Cash over and short', 'type' => 'expense',
                'subtype' => 'operating', 'system_key' => 'cash_over_short',
            ]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};

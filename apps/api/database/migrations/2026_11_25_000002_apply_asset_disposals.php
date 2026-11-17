<?php

use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Applies database/schema/043_asset_disposals.sql (Module 9c): disposing of fixed assets. A disposal posts its gain or
 * loss to "Gain or loss on disposal of assets", a system account new companies get from the chart template; companies
 * set up before it existed get it here, with the first free code from 4960.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(SchemaSql::load('043_asset_disposals.sql'));

        foreach (DB::table('companies')->pluck('id') as $company) {
            if (DB::table('accounts')->where(['company_id' => $company, 'system_key' => 'asset_disposal'])->exists()) {
                continue;
            }
            $used = DB::table('accounts')->where('company_id', $company)->pluck('code')->map(fn ($c) => (string) $c)->all();
            $code = collect([...range(4960, 4969), ...range(4970, 4989)])->map(fn ($n) => (string) $n)->first(fn ($c) => ! in_array($c, $used, true));
            if ($code === null) {
                throw new RuntimeException("Company {$company} has no free code for Gain or loss on disposal of assets between 4960 and 4989.");
            }
            DB::table('accounts')->insert([
                'company_id' => $company, 'code' => $code, 'name' => 'Gain or loss on disposal of assets', 'type' => 'income',
                'subtype' => 'other_income', 'system_key' => 'asset_disposal',
            ]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Ledger rules are never rolled back. Restore from backup instead.');
    }
};

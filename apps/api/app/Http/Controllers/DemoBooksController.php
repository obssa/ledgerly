<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\FillDemoData;
use App\Domain\Tenancy\Actions\SetUpCompany;
use App\Domain\Tenancy\Support\CurrentCompany;
use App\Domain\Tenancy\Support\DatabaseTenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Two buttons, for the demo's owner (when demo sign-in is on) and for the owners of a test company (104); anywhere
 * else these routes do not exist: 404.
 *
 *  - Start fresh: a new, empty company with the same details; the old one is archived (kept, read-only) and its people
 *    move to the new one: the demo accounts (101) or every active member of the test company (104). Nothing in the
 *    books is deleted.
 *  - Fill with sample data: the sample books, put into the empty company in the background (FillDemoData).
 */
final class DemoBooksController extends Controller
{
    /** What the new company keeps from the old: who it is and how its books are kept; nothing posted. */
    private const KEPT = ['organisation_id', 'legal_name', 'trading_name', 'legal_form', 'address_line1', 'address_line2', 'city', 'region',
        'postal_code', 'country_code', 'base_currency', 'industry', 'tin', 'vat_registered', 'vat_number', 'phone', 'email', 'website',
        'calendar', 'timezone', 'fiscal_year_start_month', 'conversion_date'];

    public function __construct(private readonly CurrentCompany $current, private readonly AuditLogger $audit)
    {
    }

    public function startFresh(Request $request, SetUpCompany $setUp): JsonResponse
    {
        $this->authorizeCompany('company.manage');
        $kind = $this->kind($request);
        $user = $request->user();
        $old = $this->current->get();

        try {
            $fresh = (string) DB::selectOne("select {$kind}_fresh_start_begin() as id")->id;
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23514') {
                return response()->json(['message' => 'These books were just started fresh: try again in a minute.', 'type' => 'TooSoon'], 422);
            }
            throw $e;
        }

        $data = [];
        foreach (self::KEPT as $field) {
            $value = $old->getAttribute($field);
            if ($value !== null) {
                $data[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
            }
        }
        $setUp->handle($user, $data, $fresh);

        // Back in the old company: archive it (the state machine runs setting_up -> live -> archived), then move the demo.
        DatabaseTenantContext::set($user->getKey(), $old->getKey());
        if ($old->status === 'setting_up') {
            DB::table('companies')->where('id', $old->getKey())->update(['status' => 'live', 'updated_at' => now()]);
        }
        DB::table('companies')->where('id', $old->getKey())->update(['status' => 'archived', 'updated_at' => now()]);
        $this->audit->record($old->getKey(), 'company_archived', 'company', $old->getKey(), ['status' => $old->status], ['status' => 'archived', 'continued_in' => $fresh],
            $kind === 'demo' ? 'The demo was started fresh' : 'The test company was started fresh', $user->getKey());
        DB::select("select {$kind}_fresh_start_finish(?::uuid)", [$fresh]);
        DatabaseTenantContext::set($user->getKey(), $fresh);

        return response()->json(['company_id' => $fresh], 201);
    }

    public function fill(Request $request): JsonResponse
    {
        $this->authorizeCompany('company.manage');
        $kind = $this->kind($request);
        $company = $this->current->id();
        if (DB::table('contacts')->where('company_id', $company)->exists() || DB::table('journal_entries')->where('company_id', $company)->exists()) {
            return response()->json(['message' => 'These books already have data. Start fresh first, then fill them.', 'type' => 'NotEmpty'], 422);
        }
        // The sample's opening balances are dated at the conversion date: refused here, not later in the background.
        if ($this->current->get()->conversion_date === null) {
            return response()->json(['message' => 'Set the conversion date under Company settings first: the sample books start from it.',
                'type' => 'NoConversionDate', 'errors' => ['conversion_date' => ['Required before filling with sample data.']]], 422);
        }
        FillDemoData::dispatch($company, (string) $request->user()->getKey())->afterCommit();
        $this->audit->record($company, 'sample_data_requested', 'company', $company, null, ['kind' => $kind], null, (string) $request->user()->getKey());

        return response()->json(['queued' => true], 202);
    }

    /**
     * Whose books these are: 'demo' for the demo's owner in the demo company (demo sign-in on), 'test_company' for an
     * active owner of a test company. Nobody else has these routes: 404. The database functions check again.
     */
    private function kind(Request $request): string
    {
        $user = $request->user()->getKey();
        $company = $this->current->id();
        if (config('demo.enabled') && DB::table('demo_accounts')->where(['user_id' => $user, 'company_id' => $company, 'role' => 'owner'])->exists()) {
            return 'demo';
        }
        $membership = $this->current->membership();
        abort_unless(DB::table('test_companies')->where('company_id', $company)->exists()
            && $membership?->role === 'owner' && $membership->status === 'active', 404);

        return 'test_company';
    }
}

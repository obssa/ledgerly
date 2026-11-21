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
 * The demo's two buttons, for its owner only (anywhere else these routes do not exist: 404).
 *
 *  - Start fresh: a new, empty demo company with the same details; the old one is archived (kept, read-only) and every
 *    demo account moves to the new one (101_demo_fresh_start.sql). Nothing in the books is deleted.
 *  - Fill with demo data: the sample books, put into an empty demo company in the background (FillDemoData).
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
        $this->demoOwnerOnly($request);
        $user = $request->user();
        $old = $this->current->get();

        try {
            $fresh = (string) DB::selectOne('select demo_fresh_start_begin() as id')->id;
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23514') {
                return response()->json(['message' => 'The demo was just started fresh: try again in a minute.', 'type' => 'TooSoon'], 422);
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
            'The demo was started fresh', $user->getKey());
        DB::select('select demo_fresh_start_finish(?::uuid)', [$fresh]);
        DatabaseTenantContext::set($user->getKey(), $fresh);

        return response()->json(['company_id' => $fresh], 201);
    }

    public function fill(Request $request): JsonResponse
    {
        $this->authorizeCompany('company.manage');
        $this->demoOwnerOnly($request);
        $company = $this->current->id();
        if (DB::table('contacts')->where('company_id', $company)->exists() || DB::table('journal_entries')->where('company_id', $company)->exists()) {
            return response()->json(['message' => 'The demo already has data. Start fresh first, then fill it.', 'type' => 'NotEmpty'], 422);
        }
        FillDemoData::dispatch($company, (string) $request->user()->getKey())->afterCommit();

        return response()->json(['queued' => true], 202);
    }

    /** These routes exist only for the demo's owner, in the demo company. */
    private function demoOwnerOnly(Request $request): void
    {
        $isDemoOwner = DB::table('demo_accounts')
            ->where(['user_id' => $request->user()->getKey(), 'company_id' => $this->current->id(), 'role' => 'owner'])->exists();
        abort_unless($isDemoOwner && config('demo.enabled'), 404);
    }
}

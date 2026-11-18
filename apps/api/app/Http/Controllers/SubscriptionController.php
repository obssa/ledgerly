<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Module 11: the company's subscription to Ledgerly as its people see it: plan, status, when the trial ends or what is
 * paid until, whether the books are read-only. Owners and admins also see how to pay. Changing it is the operator's
 * (ledgerly:subscription, as the owner role); the application cannot (053).
 */
final class SubscriptionController extends Controller
{
    public function __construct(private readonly CurrentCompany $current)
    {
    }

    public function show(): JsonResponse
    {
        $this->authorizeCompany('company.view');
        $org = DB::table('organisations')->where('id', $this->current->get()->organisation_id)
            ->first(['plan', 'status', 'trial_ends_at', 'paid_until', 'past_due_since']);
        $today = new \DateTimeImmutable($this->current->today());
        $until = match ($org->status) {
            'trial' => $org->trial_ends_at ? (new \DateTimeImmutable((string) $org->trial_ends_at))->format('Y-m-d') : null,
            'active', 'past_due' => $org->paid_until,
            default => null,
        };
        $manage = (bool) $this->current->membership()?->can('company.manage');

        return response()->json([
            'plan' => $org->plan, 'plan_name' => config("billing.plans.{$org->plan}", ucfirst((string) $org->plan)),
            'status' => $org->status, 'until' => $until,
            'days_left' => $until === null ? null : (int) $today->diff(new \DateTimeImmutable($until))->format('%r%a'),
            'read_only' => in_array($org->status, ['suspended', 'cancelled'], true),
            'demo' => (bool) DB::selectOne('SELECT EXISTS (SELECT 1 FROM demo_accounts WHERE company_id = ?) AS d', [$this->current->id()])->d,
            'enforced' => (bool) config('billing.enforce'),
            'instructions' => $manage ? (string) config('billing.instructions') : null,
            'contact' => (string) config('billing.contact'),
            'can' => ['manage' => $manage],
        ]);
    }
}

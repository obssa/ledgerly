<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The company's active members by name, for pickers such as the salesperson on an invoice. Names only: roles, emails
 * and invitations stay with the owner's and admins' user management.
 */
final class PeopleController extends Controller
{
    public function __construct(private readonly CurrentCompany $current)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorizeCompany('company.view');

        return response()->json(['data' => DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where(['m.company_id' => $this->current->id(), 'm.status' => 'active'])->orderBy('u.name')->limit(500)->get(['u.id', 'u.name'])]);
    }
}

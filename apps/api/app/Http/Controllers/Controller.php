<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\Support\CurrentCompany;
use Symfony\Component\HttpKernel\Exception\HttpException;

abstract class Controller
{
    /** Checks the signed-in user's role in the current company (config/permissions.php). */
    protected function authorizeCompany(string $permission): void
    {
        $membership = app(CurrentCompany::class)->membership();

        if (! $membership || ! $membership->can($permission)) {
            throw new HttpException(403, "Your role does not allow {$permission}.");
        }
    }
}

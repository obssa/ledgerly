<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\DemoAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Demo sign-in (config/demo.php): one click signs in to the demo company as a role. Answers 404 when demo sign-in
 * is off, so a deployment without it shows nothing.
 */
final class DemoController extends Controller
{
    /** The roles the sign-in page offers. */
    public function index(): JsonResponse
    {
        abort_unless(DemoAccounts::enabled(), 404);

        $roles = config('demo.roles');

        return response()->json([
            'company' => config('demo.company'),
            'roles' => array_map(fn (string $r) => ['role' => $r, 'label' => $roles[$r]['label'], 'about' => $roles[$r]['about']], DemoAccounts::roles()),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        abort_unless(DemoAccounts::enabled(), 404);

        $data = $request->validate(['role' => ['required', 'string', Rule::in(array_keys((array) config('demo.roles')))]]);
        $user = DemoAccounts::forRole($data['role']);
        abort_if($user === null, 404, 'The demo is not set up yet.');

        $token = $user->createToken('demo', ['*'], now()->addMinutes((int) config('demo.token_minutes')))->plainTextToken;
        Log::info('auth.demo_login', ['role' => $data['role']]);

        return response()->json(['user' => $user, 'token' => $token, 'role' => $data['role']]);
    }
}

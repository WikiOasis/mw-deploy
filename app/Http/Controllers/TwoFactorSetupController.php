<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Enrolment screen for TOTP. Reachable without TOTP by design — otherwise a user
 * who needs to enrol could never get here (see RequireTwoFactor).
 */
final class TwoFactorSetupController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('auth.two-factor-setup', [
            'user' => $user,
            'required' => $user->requiresTwoFactor(),
            'enabled' => $user->hasTwoFactorEnabled(),
            'pendingConfirmation' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null,
            'reasons' => array_values(array_intersect(
                Permissions::requiringTwoFactor(),
                $user->permissionNames(),
            )),
        ]);
    }
}

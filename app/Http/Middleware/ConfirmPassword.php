<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\Middleware\RequirePassword;

/**
 * Laravel's password confirmation, with the one case it cannot express: an
 * account that has no password.
 *
 * Enabling or disabling TOTP is behind this gate (see config/fortify.php), and an
 * account provisioned by single sign-on has nothing to confirm with. Left alone,
 * the gate is a dead end rather than a check — the account menu offers everyone a
 * two-factor screen, and following it landed a passwordless account on a form it
 * could never satisfy.
 *
 * Such an account is treated as confirmed by virtue of the session it already
 * holds from the identity provider, which is the only credential it has. Every
 * account that does have a password confirms it the ordinary way, unchanged: the
 * exemption is for accounts with no password, not for accounts signed in by SSO,
 * because a password is a way in the provider never sees.
 */
final class ConfirmPassword extends RequirePassword
{
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null)
    {
        $user = $request->user();

        if ($user instanceof User && ! filled($user->password)) {
            return $next($request);
        }

        return parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
    }
}

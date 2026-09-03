<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Section 3.5.1: any account with deploy permissions beyond read-only must have
 * TOTP enrolled. This app can push code to 700+ wikis' production servers, so a
 * password on its own is not enough.
 *
 * Read-only accounts are unaffected, and the enrolment screen itself is exempt
 * so people can actually get enrolled.
 *
 * Accounts that can only be entered through the identity provider are exempt too
 * — see User::requiresTwoFactor(). They have no password, so the provider is the
 * only gate and the second factor is the provider's to enforce; an account that
 * keeps a password alongside is still required to enrol, because that password is
 * a way in the provider never sees.
 */
final class RequireTwoFactor
{
    /** Route names reachable without TOTP so enrolment is possible. */
    private const EXEMPT = [
        'two-factor.setup',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'profile.show',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->requiresTwoFactor() || $user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        if ($this->isExempt($request)) {
            return $next($request);
        }

        $message = 'Your account can change production, so two-factor authentication is required before you can continue.';

        // The SPA's API cannot follow a redirect to an HTML enrolment page, so it
        // is told outright. The client turns this into the same enrolment prompt.
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => $message,
                'two_factor_required' => true,
                'enrol_url' => route('two-factor.setup'),
            ], 403);
        }

        return redirect()
            ->route('two-factor.setup')
            ->with('status', $message);
    }

    private function isExempt(Request $request): bool
    {
        $name = $request->route()?->getName();

        if ($name !== null && in_array($name, self::EXEMPT, true)) {
            return true;
        }

        // Fortify publishes its two-factor endpoints under /user/*.
        return $request->is('user/*', 'two-factor*', 'logout');
    }
}

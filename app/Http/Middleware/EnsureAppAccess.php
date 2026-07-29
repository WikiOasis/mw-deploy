<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Apps\AppRegistry;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The console's app boundary: every one of an app's API routes is behind
 * `app.access:<id>`, so an account with no grant inside an app cannot reach any
 * of it — not just the screens the launcher would have hidden.
 *
 * The per-action policies still run underneath. This is the outer door, not a
 * replacement for them.
 */
final class EnsureAppAccess
{
    public function __construct(private readonly AppRegistry $registry) {}

    public function handle(Request $request, Closure $next, string $app): Response
    {
        $definition = $this->registry->find($app);

        // An app this install has switched off does not answer at all, rather
        // than answering 403 and implying a grant would help.
        if ($definition === null || ! $definition->isEnabled()) {
            abort(404);
        }

        $user = $request->user();

        if ($user instanceof User && $definition->accessibleBy($user)) {
            return $next($request);
        }

        $message = 'You do not have access to the '.$definition->name().' app.';

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => $message,
                'app_access_required' => $definition->id(),
                'launcher_url' => url('/'),
            ], 403);
        }

        return redirect('/')->with('status', $message);
    }
}

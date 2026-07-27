<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Api\BootstrapController;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Serves the single-page app for every application path.
 *
 * The bootstrap payload — who is signed in, what they may do, the deploy-wide
 * settings — is inlined into the document rather than fetched, so the first paint
 * is the real chrome with the real permissions rather than a skeleton waiting on a
 * round trip. Everything after that is the API.
 */
final class SpaController extends Controller
{
    public function __invoke(Request $request, BootstrapController $bootstrap): View
    {
        return view('app', [
            'bootstrap' => $bootstrap->payload($request->user()),
        ]);
    }
}

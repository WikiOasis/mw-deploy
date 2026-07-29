<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Apps\AppRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The launcher's list: every app this install has switched on, each flagged with
 * whether the signed-in account may open it.
 *
 * Also inlined into the bootstrap payload, so the launcher paints without a
 * round trip; this endpoint exists for the refresh after someone's grants change.
 */
final class AppController extends Controller
{
    public function __invoke(Request $request, AppRegistry $registry): JsonResponse
    {
        return response()->json([
            'apps' => $registry->launcherFor($request->user()),
        ]);
    }
}

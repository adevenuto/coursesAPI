<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePremium
{
    /**
     * Gate premium (green-center) endpoints to paid plans.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasPremium()) {
            return response()->json([
                'message' => 'Green-center data requires a Pro or Max plan.',
                'upgrade_url' => url('/#pricing'),
            ], 403);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TrackApiUsage
{
    /**
     * Increment the authenticated user's daily request counter (for the
     * dashboard usage view + future billing). Runs after the request so it
     * only counts allowed (non-throttled) calls.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if ($user) {
            $today = now()->toDateString();

            $updated = DB::table('api_usage')
                ->where('user_id', $user->id)
                ->where('usage_date', $today)
                ->increment('requests');

            if (! $updated) {
                try {
                    DB::table('api_usage')->insert([
                        'user_id' => $user->id,
                        'usage_date' => $today,
                        'requests' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (\Throwable) {
                    // Lost an insert race — the row now exists, so increment it.
                    DB::table('api_usage')
                        ->where('user_id', $user->id)
                        ->where('usage_date', $today)
                        ->increment('requests');
                }
            }
        }

        return $response;
    }
}

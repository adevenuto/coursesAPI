<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Reads the per-user daily API usage recorded by TrackApiUsage.
 */
class ApiUsage
{
    public function today(int $userId): int
    {
        return (int) DB::table('api_usage')
            ->where('user_id', $userId)
            ->where('usage_date', now()->toDateString())
            ->value('requests');
    }

    /**
     * Zero-filled daily series for the last N days (oldest first).
     *
     * @return list<array{date:string,requests:int}>
     */
    public function series(int $userId, int $days = 14): array
    {
        $rows = DB::table('api_usage')
            ->where('user_id', $userId)
            ->where('usage_date', '>=', now()->subDays($days - 1)->toDateString())
            ->pluck('requests', 'usage_date');

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $series[] = ['date' => $date, 'requests' => (int) ($rows[$date] ?? 0)];
        }

        return $series;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded API call.
 *
 * Rows are written by TrackApiRequest via a plain query-builder insert (no model
 * hydration on the request path) and read back in aggregate by ApiAnalytics.
 * This model exists for the factory, the seeder, and tests.
 *
 * @property int $user_id
 * @property int|null $token_id
 * @property string|null $token_name
 * @property string $method
 * @property string $endpoint
 * @property int $status
 * @property int|null $duration_ms
 * @property int|null $response_bytes
 * @property int|null $result_count
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $client
 * @property string|null $search_term
 * @property array<string,mixed>|null $query
 */
class ApiRequest extends Model
{
    use HasFactory;

    /** Append-only: a request is recorded once and never modified. */
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'query' => 'array',
            'created_at' => 'datetime',
            'status' => 'integer',
            'duration_ms' => 'integer',
            'result_count' => 'integer',
            'response_bytes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

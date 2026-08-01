<?php

namespace App\Http\Resources\Concerns;

use Illuminate\Http\Request;

/**
 * Trim Laravel's verbose paginated-resource output down to the fields API
 * consumers actually use (drops the meta.links[] page-number array).
 */
trait CleanPagination
{
    /**
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [
            'links' => [
                'first' => $default['links']['first'] ?? null,
                'last' => $default['links']['last'] ?? null,
                'prev' => $default['links']['prev'] ?? null,
                'next' => $default['links']['next'] ?? null,
            ],
            'meta' => [
                'current_page' => $paginated['current_page'] ?? null,
                'per_page' => $paginated['per_page'] ?? null,
                'last_page' => $paginated['last_page'] ?? null,
                'total' => $paginated['total'] ?? null,
            ],
        ];
    }
}

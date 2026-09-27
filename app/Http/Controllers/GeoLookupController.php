<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public (IP-throttled) name lookups for the "suggest a course" modal's
 * cascading country → state → city typeaheads.
 *
 * These exist because Algolia cannot serve this: `shouldBeSearchable()` on the
 * geo models restricts those indices to course-bearing rows — 11,315 of 152,970
 * cities — and a town with no courses yet is precisely the town someone
 * reporting a missing course needs to pick. These query the FULL tables.
 *
 * Separate from Api\V1\GeoController, which does the same cascade but sits
 * behind auth:sanctum and bills the caller's quota, and from ExploreController,
 * whose job is courses-within-a-known-area rather than searching for the area.
 *
 * The parent id is required on states and cities, and that is a safety property
 * rather than only cascade semantics: it bounds every scan. Unscoped, a
 * leading-wildcard LIKE over `cities` is 152,970 rows on an unauthenticated
 * endpoint; scoped, the worst case is 1,757.
 */
class GeoLookupController extends Controller
{
    /** Typeahead rows per response. Countries are small enough to send whole. */
    private const LIMIT = 20;

    public function countries(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        // All 250 fit in a few KB, so an empty box can show a browsable list
        // rather than nothing — and there is no cap worth enforcing.
        $rows = $this->applyNameFilter(Country::query(), $data['q'] ?? null, ['iso2', 'iso3'])
            ->get(['id', 'name', 'iso2'])
            ->map(fn (Country $c) => ['id' => $c->id, 'name' => $c->name, 'label' => $c->name, 'code' => $c->iso2]);

        return $this->respond($rows->all(), false);
    }

    public function states(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country' => ['required', 'integer', 'min:1'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $rows = $this->applyNameFilter(
            State::query()->where('country_id', $data['country']),
            $data['q'] ?? null,
            ['iso2'],
        )->limit(self::LIMIT + 1)->get(['id', 'name', 'iso2']);

        return $this->respond(
            $rows->take(self::LIMIT)
                ->map(fn (State $s) => ['id' => $s->id, 'name' => $s->name, 'label' => $s->name, 'code' => $s->iso2])
                ->all(),
            $rows->count() > self::LIMIT,
        );
    }

    public function cities(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', 'integer', 'min:1'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        // Scoped by state only. Without a resolved state we are already in
        // "needs manual research" territory, where the city *name* is what we
        // want from the visitor rather than a list of our guesses.
        $rows = $this->applyNameFilter(
            City::query()->where('state_id', $data['state']),
            $data['q'] ?? null,
        )->limit(self::LIMIT + 1)->get(['id', 'name', 'state_id']);

        return $this->respond(
            $rows->take(self::LIMIT)
                ->map(fn (City $c) => ['id' => $c->id, 'name' => $c->name, 'label' => $c->name])
                ->all(),
            $rows->count() > self::LIMIT,
        );
    }

    /**
     * Forgiving name filter, prefix matches ranked first.
     *
     * LIKE wildcards are escaped. Without that, `?q=%` matches every row and a
     * typeahead becomes a table dump — note Course::scopeSearch() currently has
     * exactly that bug, so it is not the thing to copy here.
     *
     * Case- and accent-insensitivity come free from the column collation
     * (utf8mb4_0900_ai_ci), so "malaga" finds "Málaga" with no extra work.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<int, string>  $codeColumns  exact-match code columns (iso2/iso3)
     * @return Builder<TModel>
     */
    private function applyNameFilter(Builder $query, ?string $term, array $codeColumns = []): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query->orderBy('name');
        }

        $escaped = addcslashes($term, '%_\\');
        $upper = strtoupper($term);

        $query->where(function (Builder $q) use ($escaped, $upper, $codeColumns) {
            $q->where('name', 'like', "%{$escaped}%");

            foreach ($codeColumns as $column) {
                $q->orWhere($column, $upper);
            }
        });

        // Rank: an exact code match, then a name that starts with the term, then
        // the rest. Without the code tier, "us" buries United States under
        // Australia and Austria — which both contain "us" inside the name, and
        // sort earlier alphabetically.
        $tiers = [];
        $bindings = [];

        foreach ($codeColumns as $column) {
            $tiers[] = "WHEN {$column} = ? THEN 0";
            $bindings[] = $upper;
        }

        $tiers[] = 'WHEN name LIKE ? THEN 1';
        $bindings[] = "{$escaped}%";

        return $query
            ->orderByRaw('CASE '.implode(' ', $tiers).' ELSE 2 END', $bindings)
            ->orderBy('name');
    }

    /**
     * Flat {results, truncated} — deliberately not the {data, meta} envelope of
     * the versioned API, so nothing mistakes this for part of that contract.
     * `truncated` lets the UI say "keep typing" instead of implying completeness.
     *
     * @param  array<int, array<string, mixed>>  $results
     */
    private function respond(array $results, bool $truncated): JsonResponse
    {
        return response()
            ->json(['results' => $results, 'truncated' => $truncated])
            // Geo names don't change. `private` and not `public`: this runs in the
            // web group and so carries Set-Cookie, which a shared cache must
            // never store against.
            ->header('Cache-Control', 'private, max-age=600');
    }
}

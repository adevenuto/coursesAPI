# Explorer search (Laravel Scout + Algolia)

The `/explorer` page runs an **Algolia autocomplete** over four indices — courses,
cities, states, countries. The browser queries Algolia directly with a **search-only**
key; indexing happens server-side via Laravel Scout using the **admin** key. Selecting a
geo result calls a public `/explore/{city,state,country}/{id}` endpoint that returns the
area's courses + map bounds. Selecting a course opens `/courses/{id}/{slug}`.

Everything is env-driven — no code changes to go live.

## 1. Keys

Algolia dashboard → **Settings → API Keys**:

```
ALGOLIA_APP_ID=...            # Application ID
ALGOLIA_SECRET=...            # Admin API Key (server-side indexing only)
ALGOLIA_SEARCH_KEY=...        # Search-Only API Key (safe to expose to the browser)
SCOUT_PREFIX=gca_             # index name prefix → gca_courses, gca_cities, ...
```

- `ALGOLIA_SECRET` (admin) is used by Scout to build indexes and is **never** sent to the
  client. `ALGOLIA_SEARCH_KEY` (search-only) is passed to the page and used by the
  autocomplete. Keep them distinct.
- Until both `ALGOLIA_APP_ID` and `ALGOLIA_SEARCH_KEY` are set, the explorer renders a
  "search isn't configured" state.

## 2. Turn Scout on

```
SCOUT_DRIVER=algolia
```

(Leave it `null` to disable indexing entirely — model saves become no-ops.)

## 3. Push index settings + import records

```bash
php artisan scout:sync-index-settings   # searchable attrs, highlighting, ranking (config/scout.php)
php artisan scout:import "App\Models\Course"
php artisan scout:import "App\Models\City"
php artisan scout:import "App\Models\State"
php artisan scout:import "App\Models\Country"
```

- Only **course-bearing** cities/states/countries are indexed (`shouldBeSearchable()`),
  so the total is roughly **~45k records** (≈22k courses + ~22k cities + states +
  countries), not the full 152k-city dataset. Mind your Algolia plan's record limit.
- Geos rank by `course_count` (dense areas first); highlighting drives the autocomplete.
- Re-run `scout:import` anytime to rebuild; `scout:flush "App\Models\City"` clears an index.

## 4. Keeping the index fresh

With `SCOUT_DRIVER=algolia`, creating/updating/deleting a Course automatically syncs that
record. Geo records rarely change. After a bulk data change (e.g. re-geocoding), re-run the
relevant `scout:import`.

## How the pieces fit

- **`config/scout.php`** — driver, `gca_` prefix, per-index `index-settings`.
- **`config/services.php` → `algolia`** — the browser's app id + search-only key.
- **`Searchable` models** (`Course`/`City`/`State`/`Country`) — `toSearchableArray()` sets
  each record's `label`, `type`, and `url` (geo → `/explore/...`, course → `/courses/...`).
- **`ExploreController`** — public, IP-throttled geo→courses endpoints (map + results).
- **`ExplorerController` / `Explorer.vue`** — the page; `CourseSearch.vue` is the
  direct-to-Algolia autocomplete.

## Tests

The suite forces `SCOUT_DRIVER=null` (phpunit.xml), so tests never touch Algolia. Search
UI is exercised against Algolia manually once keys + import are in place.

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

class Course extends Model
{
    use Searchable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'layout_data' => 'array',
            'lat' => 'float',
            'lng' => 'float',
            'geo_confidence' => 'float',
            'needs_review' => 'boolean',
        ];
    }

    // ---- Relationships -----------------------------------------------------

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_prov_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CourseRevision::class)->latest('created_at');
    }

    // ---- Derived data (from layout_data) -----------------------------------

    /**
     * Normalized scorecard: hole count + teeboxes with per-hole par/yards/handicap.
     *
     * @return array{hole_count:int|null,teeboxes:list<array<string,mixed>>}|null
     */
    /**
     * Public scorecard. Each teebox carries the men's rating/slope and per-hole
     * handicap under the base keys, plus rating_women / slope_women / handicap_women
     * siblings. Values may be stored as a scalar (men-only, the historical shape)
     * or a [men, women] array; the *_women fields fall back to the men's value when
     * a course has no distinct women's value, so a women's column always has a number.
     *
     * @return array<string, mixed>|null
     */
    public function getScorecardAttribute(): ?array
    {
        $data = $this->layout_data;
        if (! is_array($data) || empty($data['teeboxes'])) {
            return null;
        }

        $teeboxes = [];
        foreach ($data['teeboxes'] as $tb) {
            $holes = [];
            foreach (($tb['holes'] ?? []) as $key => $h) {
                $holes[] = [
                    'hole' => (int) preg_replace('/\D/', '', (string) $key),
                    'par' => isset($h['par']) ? (int) $h['par'] : null,
                    'yards' => isset($h['length']) ? (int) $h['length'] : null,
                    'handicap' => self::intOrNull(self::pickGender($h['handicap'] ?? null, 'men')),
                    'handicap_women' => self::intOrNull(self::pickGender($h['handicap'] ?? null, 'women')),
                ];
            }
            usort($holes, fn ($a, $b) => $a['hole'] <=> $b['hole']);

            $teeboxes[] = [
                'name' => $tb['name'] ?? null,
                'rating' => self::floatOrNull(self::pickGender($tb['courseRating'] ?? null, 'men')),
                'rating_women' => self::floatOrNull(self::pickGender($tb['courseRating'] ?? null, 'women')),
                'slope' => self::intOrNull(self::pickGender($tb['slope'] ?? null, 'men')),
                'slope_women' => self::intOrNull(self::pickGender($tb['slope'] ?? null, 'women')),
                'total_yards' => isset($tb['totalYardage']) ? (int) $tb['totalYardage'] : null,
                'holes' => $holes,
            ];
        }

        return [
            'hole_count' => isset($data['hole_count']) ? (int) $data['hole_count'] : null,
            'teeboxes' => $teeboxes,
        ];
    }

    /**
     * Cast a gendered value, keeping a missing one missing.
     *
     * A plain (float)/(int) cast turns null into 0.0/0, which since
     * CourseLayoutWriter learned to store [null, women] would report a
     * women-only tee as having a men's rating of zero.
     */
    private static function floatOrNull(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }

    private static function intOrNull(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }

    /**
     * Pick the men's (index 0) or women's (index 1, falling back to men's) value
     * from a gendered field. A plain scalar is treated as the men's value.
     */
    private static function pickGender(mixed $v, string $gender): mixed
    {
        if (! is_array($v)) {
            return $v;
        }

        return $gender === 'women' ? ($v[1] ?? $v[0] ?? null) : ($v[0] ?? null);
    }

    /**
     * The raw women's value (index 1) of a gendered field, or null when there
     * is no distinct women's value (scalar men-only, or a one-element array).
     */
    private static function womenOf(mixed $v): mixed
    {
        return is_array($v) && isset($v[1]) ? $v[1] : null;
    }

    /**
     * Per-hole green centers (premium). Null when the course has none.
     *
     * @return list<array{hole:int,lat:float,lng:float}>|null
     */
    public function getGreenCentersAttribute(): ?array
    {
        $data = $this->layout_data;
        $gc = $data['greenCenters'] ?? null;
        if (! is_array($gc) || $gc === []) {
            return null;
        }

        $out = [];
        foreach ($gc as $key => $v) {
            if (isset($v['lat'], $v['lng'])) {
                $out[] = [
                    'hole' => (int) preg_replace('/\D/', '', (string) $key),
                    'lat' => (float) $v['lat'],
                    'lng' => (float) $v['lng'],
                ];
            }
        }
        usort($out, fn ($a, $b) => $a['hole'] <=> $b['hole']);

        return $out;
    }

    public function hasGreenCenters(): bool
    {
        return $this->green_centers !== null;
    }

    /**
     * Courses within a short radius, nearest first, with same-club courses
     * pinned to the top.
     *
     * The ordering is what preserves the old "other routings of this facility"
     * signal: genuine multi-course clubs almost always share one exact
     * coordinate (1,619 of ~1,635 in this dataset), so they sort first at 0.0mi
     * and carry `same_club`. Everything else is a real neighbour.
     *
     * Capped because density is wildly uneven — a typical course has 2-3
     * neighbours within 5 miles, Pinehurst and Scottsdale have 24-30.
     *
     * @return array{
     *     radius_mi: float,
     *     placeholder: array{courses:int, clubs:int}|null,
     *     courses: array<int, array<string, mixed>>
     * }
     */
    public function nearbyCourses(): array
    {
        $radiusMi = (float) config('api.nearby_radius_mi', 5);
        $empty = ['radius_mi' => $radiusMi, 'placeholder' => null, 'courses' => []];

        if ($this->lat === null || $this->lng === null) {
            return $empty;
        }

        // A coordinate shared by many *different* clubs is a geocoding
        // placeholder, not a neighbourhood — one centroid in this dataset holds
        // 87 courses from 81 unrelated clubs. Listing those as neighbours would
        // be noise; saying so is useful.
        if ($placeholder = $this->placeholderCoordinate()) {
            return [...$empty, 'placeholder' => $placeholder];
        }

        $club = trim((string) $this->club_name);

        $query = static::query()
            ->near((float) $this->lat, (float) $this->lng, $radiusMi * 1.60934)
            ->whereKeyNot($this->id)
            // scopeNear ends with orderBy('distance_km'); clear it so same-club
            // sorts first rather than landing behind distance and doing nothing.
            ->reorder();

        if ($club !== '') {
            $query->orderByRaw('CASE WHEN club_name = ? THEN 0 ELSE 1 END', [$club]);
        }

        return [...$empty, 'courses' => $query
            ->orderBy('distance_km')
            ->limit((int) config('api.nearby_limit', 12))
            ->get()
            ->map(fn (Course $c) => [
                'id' => $c->id,
                'course_name' => $c->course_name,
                'club_name' => $c->club_name,
                'hole_count' => is_array($c->layout_data) && isset($c->layout_data['hole_count'])
                    ? (int) $c->layout_data['hole_count'] : null,
                'green_centers_available' => $c->hasGreenCenters(),
                'distance_mi' => round(((float) $c->distance_km) * 0.621371, 1),
                'same_club' => $club !== '' && trim((string) $c->club_name) === $club,
                'edit_url' => '/courses/'.$c->id.'/edit',
            ])->all()];
    }

    /**
     * Is this course sitting on a shared placeholder coordinate?
     *
     * Thresholds are set against real rows: Haig Point (14 courses, 1 club) and
     * Pinehurst (9, 1) are genuine facilities and must not trip this; the
     * Australian centroid (87, 81), Cairns (13, 13) and Brisbane (12, 12) must.
     *
     * @return array{courses:int, clubs:int}|null
     */
    private function placeholderCoordinate(): ?array
    {
        $shared = static::query()
            ->where('lat', $this->lat)
            ->where('lng', $this->lng)
            ->whereKeyNot($this->id)
            ->selectRaw('COUNT(*) AS courses, COUNT(DISTINCT club_name) AS clubs')
            ->first();

        $courses = (int) ($shared->courses ?? 0);
        $clubs = (int) ($shared->clubs ?? 0);

        return $courses >= 5 && $clubs >= 3
            ? ['courses' => $courses, 'clubs' => $clubs]
            : null;
    }

    /**
     * Full editable representation for the course editor — richer than the
     * read accessors (keeps teebox color/secondaryColor, numeric hole values,
     * and the green-center list). Order is implied by array position.
     *
     * @return array<string, mixed>
     */
    public function forEditor(): array
    {
        $data = is_array($this->layout_data) ? $this->layout_data : [];

        $teeboxes = [];
        foreach (($data['teeboxes'] ?? []) as $tb) {
            $holes = [];
            foreach (($tb['holes'] ?? []) as $key => $h) {
                $hcpWomen = self::womenOf($h['handicap'] ?? null);
                $holes[] = [
                    'hole' => (int) preg_replace('/\D/', '', (string) $key),
                    'par' => isset($h['par']) ? (int) $h['par'] : null,
                    'length' => isset($h['length']) ? (int) $h['length'] : null,
                    'handicap' => isset($h['handicap']) ? (int) self::pickGender($h['handicap'], 'men') : null,
                    'handicapWomen' => $hcpWomen !== null ? (int) $hcpWomen : null,
                ];
            }
            usort($holes, fn ($a, $b) => $a['hole'] <=> $b['hole']);

            $ratingWomen = self::womenOf($tb['courseRating'] ?? null);
            $slopeWomen = self::womenOf($tb['slope'] ?? null);
            $teeboxes[] = [
                'name' => $tb['name'] ?? '',
                'color' => $tb['color'] ?? null,
                'secondaryColor' => $tb['secondaryColor'] ?? null,
                'courseRating' => self::floatOrNull(self::pickGender($tb['courseRating'] ?? null, 'men')),
                'courseRatingWomen' => self::floatOrNull($ratingWomen),
                'slope' => self::intOrNull(self::pickGender($tb['slope'] ?? null, 'men')),
                'slopeWomen' => self::intOrNull($slopeWomen),
                'totalYardage' => isset($tb['totalYardage']) ? (int) $tb['totalYardage'] : null,
                'holes' => $holes,
            ];
        }

        return [
            'id' => $this->id,
            'course_name' => $this->course_name,
            'club_name' => $this->club_name,
            'address' => $this->address,
            'postal_code' => $this->postal_code,
            'phone' => $this->phone,
            'website' => $this->website,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'hole_count' => isset($data['hole_count']) ? (int) $data['hole_count'] : null,
            'teeboxes' => $teeboxes,
            'green_centers' => $this->green_centers ?? [],
            'location' => [
                'city' => $this->city?->name,
                'state' => $this->state?->name,
                'country' => $this->country?->name,
            ],
            'last_editor' => $this->updated_by
                ? ['name' => $this->updatedBy?->name, 'at' => $this->updated_at?->diffForHumans()]
                : null,
        ];
    }

    // ---- Search (Algolia via Scout) ----------------------------------------

    /**
     * The record pushed to the `courses` index. `url` points at the course
     * show page; `type` lets the explorer decide navigate-vs-fetch.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $city = $this->city?->name;
        $state = $this->state?->name;
        $country = $this->country?->name;

        return [
            'id' => $this->id,
            'name' => $this->course_name,
            'club' => $this->club_name,
            'city' => $city,
            'state' => $state,
            'country' => $country,
            'label' => collect([$this->club_name ?: $this->course_name, $city, $state, $country])
                ->filter()->implode(', '),
            'lat' => $this->lat,
            'lng' => $this->lng,
            'type' => 'course',
            'green_centers_available' => $this->hasGreenCenters(),
            'url' => '/courses/'.$this->id.'/'.$this->urlSlug(),
        ];
    }

    /**
     * Eager-load the geo names so bulk indexing doesn't N+1.
     *
     * @param  Collection<int, Course>  $models
     * @return Collection<int, Course>
     */
    public function makeSearchableUsing(Collection $models): Collection
    {
        return $models->load('city:id,name', 'state:id,name', 'country:id,name');
    }

    /**
     * Cosmetic slug for the course URL (resolved by id). Club-first (more
     * consistent than course_name); append the course only when it adds info.
     */
    public function urlSlug(): string
    {
        $club = trim((string) $this->club_name);
        $course = trim((string) $this->course_name);

        $slug = Str::slug($club !== '' ? $club : $course);

        if ($course !== '' && $club !== '' && Str::slug($course) !== Str::slug($club)) {
            $slug .= '_'.Str::slug($course);
        }

        return $slug !== '' ? $slug : 'course';
    }

    // ---- Scopes ------------------------------------------------------------

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('course_name', 'like', "%{$term}%")
                ->orWhere('club_name', 'like', "%{$term}%");
        });
    }

    /**
     * Filter by country (numeric id or ISO2 code), state id, or city id.
     */
    public function scopeInCountry(Builder $query, string $country): Builder
    {
        if (ctype_digit($country)) {
            return $query->where('country_id', (int) $country);
        }

        $id = Country::where('iso2', strtoupper($country))->value('id');

        return $query->where('country_id', $id ?? -1);
    }

    /**
     * Nearest-first within a radius (km): bounding-box prefilter + haversine.
     */
    public function scopeNear(Builder $query, float $lat, float $lng, float $radiusKm): Builder
    {
        $latDelta = $radiusKm / 111.0;
        $lngDelta = $radiusKm / max(1e-6, 111.0 * cos(deg2rad($lat)));
        $haversine = '(6371 * acos(least(1, cos(radians(?)) * cos(radians(lat)) * '
            .'cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat)))))';

        return $query
            ->whereNotNull('lat')->whereNotNull('lng')
            ->whereBetween('lat', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('lng', [$lng - $lngDelta, $lng + $lngDelta])
            ->select('courses.*')
            ->selectRaw("{$haversine} AS distance_km", [$lat, $lng, $lat])
            ->whereRaw("{$haversine} <= ?", [$lat, $lng, $lat, $radiusKm])
            ->orderBy('distance_km');
    }
}

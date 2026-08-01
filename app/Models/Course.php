<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Course extends Model
{
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

    // ---- Derived data (from layout_data) -----------------------------------

    /**
     * Normalized scorecard: hole count + teeboxes with per-hole par/yards/handicap.
     *
     * @return array{hole_count:int|null,teeboxes:list<array<string,mixed>>}|null
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
                    'handicap' => isset($h['handicap']) ? (int) $h['handicap'] : null,
                ];
            }
            usort($holes, fn ($a, $b) => $a['hole'] <=> $b['hole']);

            $teeboxes[] = [
                'name' => $tb['name'] ?? null,
                'rating' => isset($tb['courseRating']) ? (float) $tb['courseRating'] : null,
                'slope' => isset($tb['slope']) ? (int) $tb['slope'] : null,
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

    public function getGreenCentersSourceAttribute(): ?string
    {
        $data = $this->layout_data;
        if (! is_array($data) || empty($data['greenCenters'])) {
            return null;
        }

        return $data['greenCentersSource'] ?? 'golftrax';
    }

    public function hasGreenCenters(): bool
    {
        return $this->green_centers !== null;
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

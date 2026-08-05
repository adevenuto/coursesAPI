<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class Country extends Model
{
    use Searchable;

    protected $guarded = [];

    // IDs come from the external geo dataset, not auto-increment.
    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'population' => 'integer',
            'gdp' => 'integer',
            'area_sq_km' => 'float',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
        ];
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function subregion(): BelongsTo
    {
        return $this->belongsTo(Subregion::class);
    }

    public function states(): HasMany
    {
        return $this->hasMany(State::class);
    }

    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'country_id');
    }

    // ---- Search (Algolia via Scout) ----------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'iso2' => $this->iso2,
            'label' => $this->name,
            'lat' => (float) $this->latitude,
            'lng' => (float) $this->longitude,
            'course_count' => $this->courses_count ?? $this->courses()->count(),
            'type' => 'country',
            'url' => config('app.url').'/explore/country/'.$this->id,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return $this->courses()->exists();
    }

    /**
     * @param  Collection<int, Country>  $models
     * @return Collection<int, Country>
     */
    public function makeSearchableUsing(Collection $models): Collection
    {
        return $models->loadCount('courses');
    }
}

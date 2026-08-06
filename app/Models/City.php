<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class City extends Model
{
    use Searchable;

    protected $guarded = [];

    // IDs come from the external geo dataset, not auto-increment.
    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'population' => 'integer',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
        ];
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'city_id');
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
            'label' => collect([$this->name, $this->state_name, $this->country_name])
                ->filter()->implode(', '),
            'lat' => (float) $this->latitude,
            'lng' => (float) $this->longitude,
            'course_count' => $this->courses_count ?? $this->courses()->count(),
            'type' => 'city',
            'url' => '/explore/city/'.$this->id,
        ];
    }

    /**
     * Only index cities that actually have courses (relevance + Algolia cost).
     */
    public function shouldBeSearchable(): bool
    {
        return $this->courses()->exists();
    }

    /**
     * @param  Collection<int, City>  $models
     * @return Collection<int, City>
     */
    public function makeSearchableUsing(Collection $models): Collection
    {
        return $models->loadCount('courses');
    }
}

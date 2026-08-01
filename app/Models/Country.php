<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    protected $guarded = [];

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
}

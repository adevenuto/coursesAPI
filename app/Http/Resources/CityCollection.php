<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\CleanPagination;
use Illuminate\Http\Resources\Json\ResourceCollection;

class CityCollection extends ResourceCollection
{
    use CleanPagination;

    public $collects = CityResource::class;
}

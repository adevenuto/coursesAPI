<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\CleanPagination;
use Illuminate\Http\Resources\Json\ResourceCollection;

class CourseCollection extends ResourceCollection
{
    use CleanPagination;

    public $collects = CourseResource::class;
}

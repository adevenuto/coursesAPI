<?php

namespace App\Http\Requests;

use App\Support\Distance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCoursesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxPerPage = (int) config('api.pagination.max_per_page', 100);
        // The radius cap depends on the unit asked for, so read `units` before
        // it is validated. An invalid unit falls back to miles for the cap and
        // is rejected by its own rule below.
        $maxRadius = Distance::maxRadius(Distance::unit($this->query('units')));

        return [
            'q' => ['sometimes', 'string', 'max:120'],
            'country' => ['sometimes', 'string', 'max:60'],
            'state_prov_id' => ['sometimes', 'integer', 'min:1'],
            'city_id' => ['sometimes', 'integer', 'min:1'],
            'lat' => ['sometimes', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['sometimes', 'numeric', 'between:-180,180', 'required_with:lat'],
            'radius' => ['sometimes', 'numeric', 'min:0.1', "max:{$maxRadius}"],
            'units' => ['sometimes', 'string', Rule::in(Distance::UNITS)],
            'per_page' => ['sometimes', 'integer', 'min:1', "max:{$maxPerPage}"],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

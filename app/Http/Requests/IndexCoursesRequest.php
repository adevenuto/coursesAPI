<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        $maxRadius = (float) config('api.max_radius_km', 100);

        return [
            'q' => ['sometimes', 'string', 'max:120'],
            'country' => ['sometimes', 'string', 'max:60'],
            'state_prov_id' => ['sometimes', 'integer', 'min:1'],
            'city_id' => ['sometimes', 'integer', 'min:1'],
            'lat' => ['sometimes', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['sometimes', 'numeric', 'between:-180,180', 'required_with:lat'],
            'radius' => ['sometimes', 'numeric', 'min:0.1', "max:{$maxRadius}"],
            'per_page' => ['sometimes', 'integer', 'min:1', "max:{$maxPerPage}"],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

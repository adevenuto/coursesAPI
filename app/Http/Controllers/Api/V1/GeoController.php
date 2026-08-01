<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CityCollection;
use App\Http\Resources\CountryResource;
use App\Http\Resources\StateResource;
use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GeoController extends Controller
{
    /** Full country list (small, unpaginated) to power filters. */
    public function countries(): AnonymousResourceCollection
    {
        $countries = Country::orderBy('name')->get(['id', 'name', 'iso2', 'iso3']);

        return CountryResource::collection($countries);
    }

    /** States/provinces for a country (id or ISO2 via ?country=). */
    public function states(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'country' => ['required', 'string', 'max:60'],
        ]);

        $country = $data['country'];
        $countryId = ctype_digit($country)
            ? (int) $country
            : Country::where('iso2', strtoupper($country))->value('id');

        $states = State::where('country_id', $countryId ?? -1)
            ->orderBy('name')
            ->get(['id', 'name', 'iso2', 'country_id']);

        return StateResource::collection($states);
    }

    /** Cities within a state (paginated — can be large). */
    public function cities(Request $request): CityCollection
    {
        $data = $request->validate([
            'state_prov_id' => ['required', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('api.pagination.max_per_page', 100)],
        ]);

        $cities = City::where('state_id', $data['state_prov_id'])
            ->orderBy('name')
            ->paginate((int) ($data['per_page'] ?? config('api.pagination.default_per_page', 25)))
            ->withQueryString();

        return new CityCollection($cities);
    }
}

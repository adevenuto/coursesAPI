<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\IpCountry;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private readonly IpCountry $ipCountry) {}

    /**
     * Validate and create a newly registered user.
     *
     * The country is resolved from the registering browser's address and stored
     * as a code; the address itself is not kept. IpCountry swallows every
     * failure and returns null, so a slow or unreachable provider costs this
     * signup a nice-to-have rather than the account.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = new User([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        // Assigned rather than mass-assigned. User's #[Fillable] lists exactly
        // what a registration form may set, and this is derived server-side —
        // widening that attribute would let a future User::create($request->all())
        // take a country straight from the request body.
        $user->signup_country = $this->ipCountry->lookup(request()->ip());
        $user->save();

        return $user;
    }
}

<?php

namespace App\Http\Requests;

use App\Models\CourseSuggestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCourseSuggestionRequest extends FormRequest
{
    /**
     * Public on purpose — anyone browsing the explorer can report a course we
     * are missing. Abuse is handled by the `suggestions` throttle on the route
     * and the honeypot the controller checks, not by an identity requirement.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A correction sends no club of its own — the single search field only
     * yields a course, and SuggestionGeo fills the club from that row. Nulling
     * it here stops a stale or crafted POST half-filling a correction.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('type') === CourseSuggestion::TYPE_CORRECTION) {
            $this->merge(['club_name' => null]);
        }
    }

    /**
     * `course_name` is required in both modes: the correction field searches it
     * and the missing field types it, but it is one value and one column, and
     * the label snapshot stays useful even if the course is later renamed.
     *
     * The four *_id fields are validated only for shape. Existence is resolved
     * in SuggestionGeo, never asserted here — see the note there for why a 422
     * on an invisible field is the wrong answer.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(CourseSuggestion::TYPES)],

            'course_name' => ['required', 'string', 'max:255'],
            'club_name' => ['nullable', 'string', 'max:255'],
            'course_id' => ['nullable', 'integer', 'min:1'],

            // Country first, matching the form's cascade. Required for a missing
            // course: the 250-country list is complete, so it is always
            // answerable, and a bare name with no country is often unresearchable.
            'country' => ['required_if:type,'.CourseSuggestion::TYPE_MISSING, 'nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'country_id' => ['nullable', 'integer', 'min:1'],
            'state_id' => ['nullable', 'integer', 'min:1'],
            'city_id' => ['nullable', 'integer', 'min:1'],

            // http/https only: this URL ends up in an email a human clicks, so
            // javascript: and mailto: have no business passing validation.
            'website' => ['nullable', 'url:http,https', 'max:255'],

            // The whole payload of a correction, so required there and only there.
            'notes' => ['required_if:type,'.CourseSuggestion::TYPE_CORRECTION, 'nullable', 'string', 'max:2000'],

            // Required so a suggestion can be followed up on — most of them
            // need one question answered before the course can be added.
            'submitter_email' => ['required', 'email', 'max:255'],

            // Honeypot. Captured, never enforced — a `max:0` rule would answer
            // with a 422 naming the field, which tells a bot exactly what to
            // drop next time. The controller acts on it silently instead.
            'contact_reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $correction = $this->input('type') === CourseSuggestion::TYPE_CORRECTION;

        return [
            'type.required' => 'Choose what you are suggesting.',
            'type.in' => 'Choose what you are suggesting.',
            'course_name.required' => $correction
                ? 'Search for the course or club you want corrected.'
                : 'Tell us the name of the course or club.',
            'country.required_if' => 'Which country is it in?',
            'notes.required_if' => 'Tell us what needs fixing.',
            'notes.max' => 'Please keep notes under 2,000 characters.',
            'website.url' => 'The website needs to be a full URL, starting with https://.',
            'submitter_email.required' => 'We need an email address so we can follow up.',
            'submitter_email.email' => 'That email address does not look right.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['submitter_email' => 'email address'];
    }
}

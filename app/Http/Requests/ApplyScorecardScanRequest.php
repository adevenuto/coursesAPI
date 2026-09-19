<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyScorecardScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canEditCourses();
    }

    /**
     * The keys of the diff sections the editor accepted, plus any out-of-range
     * values they approved. Anything absent is left as it was on the course, so
     * an empty list is a no-op rather than a wipe.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'sections' => ['present', 'array', 'max:40'],
            'sections.*' => ['string', 'max:40'],

            // Range-check keys the editor explicitly approved, from the
            // `override` field the verifier puts on range issues. Absent means
            // nothing was approved, which is the normal case.
            'overrides' => ['sometimes', 'array', 'max:60'],
            'overrides.*' => ['string', 'max:60'],
        ];
    }
}

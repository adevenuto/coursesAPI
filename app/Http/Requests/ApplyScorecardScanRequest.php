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
     * The keys of the diff sections the editor accepted. Anything absent is left
     * as it was on the course, so an empty list is a no-op rather than a wipe.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'sections' => ['present', 'array', 'max:40'],
            'sections.*' => ['string', 'max:40'],
        ];
    }
}

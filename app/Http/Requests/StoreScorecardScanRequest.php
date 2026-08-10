<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreScorecardScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canEditCourses();
    }

    /**
     * Up to four images so a card can be shot in halves, or front and back
     * captured together. Everything is re-encoded by ScorecardImage, so the
     * size ceiling only needs to stop absurd uploads, not match the API limit.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:4'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:12288'],

            // Absent means "this scan will create a new course".
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'images.required' => 'Choose at least one scorecard image.',
            'images.max' => 'Upload at most 4 images per scan.',
            'images.*.mimes' => 'Scorecard images must be JPG, PNG or WebP.',
            'images.*.max' => 'Each image must be 12 MB or smaller.',
        ];
    }
}

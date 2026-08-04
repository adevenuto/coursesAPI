<?php

namespace App\Http\Requests;

use App\Concerns\CourseValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreCourseRequest extends FormRequest
{
    use CourseValidationRules;

    public function authorize(): bool
    {
        return (bool) $this->user()?->canEditCourses();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->courseRules();
    }
}

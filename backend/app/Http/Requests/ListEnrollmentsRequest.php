<?php

namespace App\Http\Requests;

use App\Models\Enrollment;
use Illuminate\Foundation\Http\FormRequest;

class ListEnrollmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('viewAny', Enrollment::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'filter' => ['sometimes', 'array'],
            'filter.course_ids' => ['sometimes', 'required', 'array', 'max:100'],
            'filter.course_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}

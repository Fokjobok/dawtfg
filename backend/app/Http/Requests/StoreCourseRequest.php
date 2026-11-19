<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Course::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'specialty_id' => ['required', 'integer', 'exists:specialties,id'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'additional_coach_ids' => ['sometimes', 'array', 'max:100'],
            'additional_coach_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'coach_id' => [Rule::requiredIf($this->user()?->role === UserRole::Administrator), 'integer', 'exists:users,id'],
            'cover' => ['sometimes', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
            'status' => ['sometimes', 'required', Rule::in(['draft', 'published'])],
        ];
    }
}

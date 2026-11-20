<?php

namespace App\Http\Requests;

use App\Enums\CourseLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('course')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $ids = $this->input('additional_coach_ids');
        // A JSON array part can represent an empty team in multipart requests.
        if (! $this->isJson() && is_string($ids) && strlen($ids) <= 4096 && str_starts_with(trim($ids), '[')) {
            $decoded = json_decode($ids, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && array_is_list($decoded)) {
                $this->merge(['additional_coach_ids' => $decoded]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'max:10000'],
            'specialty_id' => ['sometimes', 'required', 'integer', 'exists:specialties,id'],
            'coach_id' => ['sometimes', 'required', 'integer', 'exists:users,id'],
            'price' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'status' => ['sometimes', 'required', Rule::in(['draft', 'published'])],
            'level' => ['sometimes', 'required', Rule::enum(CourseLevel::class)],
            'additional_coach_ids' => ['sometimes', 'array', 'max:100'],
            'additional_coach_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'cover' => ['sometimes', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=2048'],
            'remove_cover' => ['sometimes', 'boolean'],
            'cover_path' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $fields = ['title', 'description', 'specialty_id', 'coach_id', 'price', 'status', 'level', 'additional_coach_ids', 'cover', 'remove_cover'];
            if (! array_intersect($fields, array_keys($this->all()))) {
                $validator->errors()->add('course', 'Provide at least one editable field.');
            }
            if ($this->boolean('remove_cover') && $this->hasFile('cover')) {
                $validator->errors()->add('cover', 'Choose either a replacement cover or removal.');
            }
        }];
    }
}

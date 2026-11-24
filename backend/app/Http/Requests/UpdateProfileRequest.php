<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use JsonException;

class UpdateProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $ids = $this->input('featured_course_ids');
        if (is_string($ids) && strlen($ids) <= 128) {
            try {
                $decoded = json_decode($ids, flags: JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $this->merge(['featured_course_ids' => $decoded]);
                }
            } catch (JsonException) {
                // Leave invalid JSON as a string so the array validation rejects it.
            }
        }
    }

    public function authorize(): bool
    {
        $actor = $this->user('web');

        return $actor?->can('updateProfile', $actor) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_private' => ['sometimes', 'required', 'boolean'],
            'bio_is_private' => ['sometimes', 'required', 'boolean'],
            'enrollments_is_private' => ['sometimes', 'required', 'boolean'],
            'medals_is_private' => ['sometimes', 'required', 'boolean'],
            'medal_ids' => ['prohibited'],
            'medals' => ['prohibited'],
            'featured_course_ids' => ['sometimes', 'present', 'array', 'max:3'],
            'featured_course_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'avatar' => ['sometimes', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=1024,max_height=1024'],
            'remove_avatar' => ['sometimes', 'boolean'],
            'avatar_path' => ['prohibited'],
            'role' => ['prohibited'],
            'email' => ['prohibited'],
            'password' => ['prohibited'],
            'user_id' => ['prohibited'],
            'id' => ['prohibited'],
            'specialty_ids' => ['prohibited'],
            'level' => ['prohibited'],
            'experience' => ['prohibited'],
            'specialty_ranks' => ['prohibited'],
            'profile_save_count' => ['prohibited'],
            'profile_save_available_at' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! array_intersect(['name', 'bio', 'is_private', 'bio_is_private', 'enrollments_is_private', 'medals_is_private', 'featured_course_ids', 'avatar', 'remove_avatar'], array_keys($this->all()))) {
                $validator->errors()->add('profile', 'Provide at least one editable field.');
            }
            if ($this->boolean('remove_avatar') && $this->hasFile('avatar')) {
                $validator->errors()->add('avatar', 'Choose either a replacement avatar or removal.');
            }
        }];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProfileRequest extends FormRequest
{
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
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! array_intersect(['name', 'bio', 'is_private', 'bio_is_private', 'avatar', 'remove_avatar'], array_keys($this->all()))) {
                $validator->errors()->add('profile', 'Provide at least one editable field.');
            }
            if ($this->boolean('remove_avatar') && $this->hasFile('avatar')) {
                $validator->errors()->add('avatar', 'Choose either a replacement avatar or removal.');
            }
        }];
    }
}

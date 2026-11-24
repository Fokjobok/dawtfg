<?php

namespace App\Http\Requests;

use App\Models\Medal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMedalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', Medal::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('medals')->where('specialty_id', $this->input('specialty_id'))],
            'specialty_id' => ['required', 'integer', 'exists:specialties,id'],
            'image' => ['sometimes', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024', 'dimensions:max_width=512,max_height=512'],
            'image_path' => ['prohibited'],
            'user_ids' => ['prohibited'],
        ];
    }
}

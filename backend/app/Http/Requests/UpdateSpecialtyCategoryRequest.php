<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSpecialtyCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('updateCategory', $this->route('specialty')) ?? false;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['present', 'nullable', 'integer', 'exists:specialty_categories,id'],
            'name' => ['prohibited'],
        ];
    }
}

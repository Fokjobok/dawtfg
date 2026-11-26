<?php

namespace App\Http\Requests;

use App\Models\Specialty;
use Illuminate\Foundation\Http\FormRequest;

class StoreSpecialtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Specialty::class) ?? false;
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
            'name' => ['required', 'string', 'max:100', 'unique:specialties,name'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:specialty_categories,id'],
        ];
    }
}

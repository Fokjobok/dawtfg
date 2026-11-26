<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListCatalogSpecialtiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'filter' => ['sometimes', 'array:category_id'],
            'filter.category_id' => ['sometimes', 'required', 'integer', 'min:1'],
        ];
    }
}

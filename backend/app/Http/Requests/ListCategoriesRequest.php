<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;

class ListCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->routeIs('api.v1.catalog.categories')
            || ($this->user('web')?->can('viewAny', Category::class) ?? false);
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}

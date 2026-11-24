<?php

namespace App\Http\Requests;

use App\Models\Medal;
use Illuminate\Foundation\Http\FormRequest;

class ListMedalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('viewAny', Medal::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'filter' => ['sometimes', $this->routeIs('api.v1.medals.recipients') ? 'array:name' : 'array:specialty_id'],
            'filter.specialty_id' => ['sometimes', 'integer', 'min:1', 'exists:specialties,id'],
            'filter.name' => ['sometimes', 'string', 'max:100'],
        ];
    }
}

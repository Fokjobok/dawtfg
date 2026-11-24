<?php

namespace App\Http\Requests;

use App\Models\Enrollment;
use Illuminate\Foundation\Http\FormRequest;

class StoreEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', Enrollment::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['prohibited'],
            'course_id' => ['prohibited'],
            'price' => ['prohibited'],
            'status' => ['prohibited'],
            'featured_order' => ['prohibited'],
        ];
    }
}

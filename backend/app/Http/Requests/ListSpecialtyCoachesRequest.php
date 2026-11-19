<?php

namespace App\Http\Requests;

class ListSpecialtyCoachesRequest extends ListSpecialtiesRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewCoaches', $this->route('specialty')) ?? false;
    }
}

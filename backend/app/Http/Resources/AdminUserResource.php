<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AdminUserResource extends UserResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'specialties' => SpecialtyResource::collection($this->whenLoaded('specialties')),
        ];
    }
}

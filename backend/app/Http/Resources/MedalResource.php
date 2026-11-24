<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MedalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'specialty' => new SpecialtyResource($this->whenLoaded('specialty')),
            'image_url' => $this->image_path ? route('api.v1.medals.image', ['medal' => $this->id], false) : null,
        ];
    }
}

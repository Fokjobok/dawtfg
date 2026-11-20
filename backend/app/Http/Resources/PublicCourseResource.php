<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicCourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'level' => $this->level?->value,
            'price' => $this->price,
            'specialty' => new SpecialtyResource($this->whenLoaded('specialty')),
            'coach' => new CoachResource($this->whenLoaded('coach')),
            'cover_url' => $this->cover_path ? route('api.v1.courses.cover', ['course' => $this->id], false) : null,
        ];
    }
}

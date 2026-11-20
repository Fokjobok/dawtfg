<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'coach_id' => $this->coach_id,
            'specialty_id' => $this->specialty_id,
            'status' => $this->status,
            'level' => $this->level?->value,
            'price' => $this->price,
            'additional_coaches' => CoachResource::collection($this->whenLoaded('additionalCoaches')),
            'cover_url' => $this->cover_path ? route('api.v1.courses.cover', ['course' => $this->id], false) : null,
        ];
    }
}

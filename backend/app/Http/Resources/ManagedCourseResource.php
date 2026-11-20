<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ManagedCourseResource extends CourseResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'coach' => $this->coach ? new CoachResource($this->coach) : null,
            'specialty' => $this->specialty ? new SpecialtyResource($this->specialty) : null,
            'can_change_responsible' => $request->user()->can('changeResponsible', $this->resource),
        ];
    }
}

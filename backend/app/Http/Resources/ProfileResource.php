<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user('web');
        $canEdit = $actor?->can('updateProfile', $this->resource) ?? false;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'level' => $this->level,
            'avatar_url' => $this->avatar_path
                ? route('api.v1.profiles.avatar', ['user' => $this->id], false).'?v='.substr(hash('sha256', $this->avatar_path), 0, 16)
                : null,
            'is_private' => (bool) $this->is_private,
            'can_edit' => $canEdit,
            'bio_is_private' => $this->when($canEdit, (bool) $this->bio_is_private),
            'enrollments_is_private' => $this->when($canEdit, (bool) $this->enrollments_is_private),
            'medals_is_private' => $this->when($canEdit, (bool) $this->medals_is_private),
            'medals' => $this->when(Gate::forUser($actor)->allows('viewProfileMedals', $this->resource), fn () => MedalResource::collection(
                $this->medals()->with('specialty')->orderBy('medals.id')->get(),
            )),
            'featured_course_ids' => $this->when($canEdit, fn () => $this->enrollments()->whereNotNull('featured_order')
                ->orderBy('featured_order')->orderBy('course_id')->pluck('course_id')->map(fn ($id) => (int) $id)->all()),
            'featured_courses' => $this->when(Gate::forUser($actor)->allows('viewProfileCourses', $this->resource), fn () => PublicCourseResource::collection(
                $this->enrolledCourses()->publiclyVisible()->wherePivotNotNull('featured_order')
                    ->orderByPivot('featured_order')->limit(3)->with(['specialty:id,name', 'coach:id,name,avatar_path,is_private,medals_is_private', 'coach.medals.specialty:id,name'])->get(),
            )),
            'save_limits' => $this->when($canEdit, function (): array {
                $now = now()->startOfSecond();
                $availableAt = $this->profile_save_available_at;
                $blocked = $availableAt?->isAfter($now) ?? false;

                return [
                    'remaining_updates' => $availableAt !== null && ! $blocked ? 3 : max(0, 3 - $this->profile_save_count),
                    'retry_after' => $blocked ? (int) ceil($now->diffInSeconds($availableAt)) : 0,
                    'available_at' => $blocked ? $availableAt->toISOString() : null,
                ];
            }),
            // Global privacy overrides section settings; owners always retain access.
            'bio' => $this->when(Gate::forUser($actor)->allows('viewProfileBio', $this->resource), $this->bio),
        ];
    }
}

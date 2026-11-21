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
            // Global privacy overrides section settings; owners always retain access.
            'bio' => $this->when(Gate::forUser($actor)->allows('viewProfileBio', $this->resource), $this->bio),
        ];
    }
}

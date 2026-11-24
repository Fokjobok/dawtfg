<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicCoachResource extends JsonResource
{
    public function __construct($resource, private readonly ?int $specialtyId = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar_url' => $this->avatar_path
                ? route('api.v1.profiles.avatar', ['user' => $this->id], false).'?v='.substr(hash('sha256', $this->avatar_path), 0, 16)
                : null,
            // Catalog cards have the same public view for every visitor, including the owner.
            'medals' => $this->when(! $this->is_private && ! $this->medals_is_private, fn () => MedalResource::collection(
                $this->medals->where('specialty_id', $this->specialtyId)->sortBy('id')->values(),
            )),
        ];
    }
}

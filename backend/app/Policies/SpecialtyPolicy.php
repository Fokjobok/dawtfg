<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Specialty;
use App\Models\User;

class SpecialtyPolicy
{
    public function viewAny(User $actor): bool
    {
        return in_array($actor->role, [UserRole::Administrator, UserRole::Coach], true);
    }

    public function create(User $actor): bool
    {
        return $actor->role === UserRole::Administrator;
    }

    public function updateCategory(User $actor, Specialty $specialty): bool
    {
        return $actor->role === UserRole::Administrator;
    }

    public function viewCoaches(User $actor, Specialty $specialty): bool
    {
        return $actor->role === UserRole::Administrator
            || ($actor->role === UserRole::Coach && $actor->specialties()->whereKey($specialty->id)->exists());
    }
}

<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->role === UserRole::Administrator;
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->role === UserRole::Administrator;
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->is($user) || $actor->role === UserRole::Administrator;
    }

    public function updateProfile(User $actor, User $user): bool
    {
        return $actor->is($user);
    }

    public function viewProfileBio(?User $actor, User $user): bool
    {
        return ($actor !== null && $actor->is($user))
            || (! $user->is_private && ! $user->bio_is_private);
    }

    public function viewProfileCourses(?User $actor, User $user): bool
    {
        return ($actor !== null && $actor->is($user))
            || (! $user->is_private && ! $user->enrollments_is_private);
    }

    public function viewProfileMedals(?User $actor, User $user): bool
    {
        return ($actor !== null && $actor->is($user))
            || (! $user->is_private && ! $user->medals_is_private);
    }
}

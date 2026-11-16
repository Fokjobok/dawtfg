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
}

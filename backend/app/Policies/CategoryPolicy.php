<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->role === UserRole::Administrator;
    }

    public function create(User $actor): bool
    {
        return $actor->role === UserRole::Administrator;
    }
}

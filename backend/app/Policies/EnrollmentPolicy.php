<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\User;

class EnrollmentPolicy
{
    public function create(User $actor): bool
    {
        return $actor->role === UserRole::Student;
    }

    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, Enrollment $enrollment): bool
    {
        return $actor->id === (int) $enrollment->user_id;
    }

    public function delete(User $actor, Enrollment $enrollment): bool
    {
        // Owners retain the ability to withdraw if their global role changes later.
        return $this->view($actor, $enrollment);
    }
}

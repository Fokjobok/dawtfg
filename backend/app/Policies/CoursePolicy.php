<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    public function create(User $actor): bool
    {
        return in_array($actor->role, [UserRole::Coach, UserRole::Administrator], true);
    }

    public function viewAny(User $actor): bool
    {
        return $this->create($actor);
    }

    public function view(User $actor, Course $course): bool
    {
        return $this->update($actor, $course);
    }

    public function update(User $actor, Course $course): bool
    {
        return $actor->role === UserRole::Administrator
            || ($actor->role === UserRole::Coach && (
                (int) $course->coach_id === $actor->id
                || $course->additionalCoaches()->whereKey($actor->id)->exists()
            ));
    }

    public function changeResponsible(User $actor, Course $course): bool
    {
        return $actor->role === UserRole::Administrator
            || ($actor->role === UserRole::Coach && (int) $course->coach_id === $actor->id);
    }

    public function delete(User $actor, Course $course): bool
    {
        return $actor->role === UserRole::Administrator
            || ($actor->role === UserRole::Coach && (int) $course->coach_id === $actor->id);
    }

    public function viewCover(?User $actor, Course $course): bool
    {
        if (Course::publiclyVisible()->whereKey($course->id)->exists()) {
            return true;
        }

        return $actor !== null && $this->update($actor, $course);
    }
}

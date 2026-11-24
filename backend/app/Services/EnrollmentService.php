<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EnrollmentService
{
    /** @return array{enrollment: Enrollment, created: bool} */
    public function enroll(User $actor, Course $course): array
    {
        return DB::transaction(function () use ($actor, $course): array {
            $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($current)->authorize('create', Enrollment::class);
            $lockedCourse = Course::whereKey($course->id)->lockForUpdate()->firstOrFail();
            abort_unless(Course::publiclyVisible()->whereKey($lockedCourse->id)->exists(), 404);
            $existing = $lockedCourse->enrollments()->where('user_id', $current->id)->first();
            if ($existing !== null) {
                return ['enrollment' => $existing, 'created' => false];
            }
            if ($lockedCourse->price !== '0.00') {
                throw ValidationException::withMessages(['course' => ['Only free courses accept enrollment.']]);
            }

            return ['enrollment' => $lockedCourse->enrollments()->create(['user_id' => $current->id]), 'created' => true];
        });
    }

    public function withdraw(User $actor, Enrollment $enrollment): void
    {
        DB::transaction(function () use ($actor, $enrollment): void {
            $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($current)->authorize('delete', $enrollment);
            // Use the same user -> course -> enrollment order as enrollment creation.
            Course::whereKey($enrollment->course_id)->lockForUpdate()->first();
            $locked = Enrollment::whereKey($enrollment->id)->lockForUpdate()->first();
            if ($locked !== null) {
                Gate::forUser($current)->authorize('delete', $locked);
                $locked->delete();
            }
        });
    }
}

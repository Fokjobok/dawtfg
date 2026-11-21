<?php

namespace App\Services;

use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class CourseDeletionService
{
    public function delete(User $actor, Course $course): void
    {
        $coverPath = DB::transaction(function () use ($actor, $course): ?string {
            $snapshot = Course::findOrFail($course->id);
            // Keep the user-before-course lock order shared with course updates.
            $users = User::whereKey(array_filter([$actor->id, $snapshot->coach_id]))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $current = Course::lockForUpdate()->findOrFail($course->id);
            $editor = $users->get($actor->id);
            abort_unless($editor, 403);
            Gate::forUser($editor)->authorize('delete', $current);

            $path = $current->cover_path;
            if (! $current->delete()) {
                throw new RuntimeException('Unable to delete the course.');
            }

            return $path;
        });

        // A failed database deletion must never remove the original image.
        if (is_string($coverPath) && $coverPath !== '') {
            try {
                if (! Storage::disk('local')->delete($coverPath)) {
                    report(new RuntimeException('Unable to clean up a deleted course cover.'));
                }
            } catch (Throwable $exception) {
                // The database deletion is already confirmed; report the orphan for cleanup.
                report($exception);
            }
        }
    }
}

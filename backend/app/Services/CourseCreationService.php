<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CourseCreationService
{
    public function create(User $actor, array $attributes): Course
    {
        $coverPath = null;

        try {
            return DB::transaction(function () use ($actor, $attributes, &$coverPath): Course {
                $ownerId = (int) ($attributes['coach_id'] ?? $actor->id);
                $additionalIds = array_map('intval', $attributes['additional_coach_ids'] ?? []);
                // Lock every participating user in the same order, shared with admin edits.
                $coaches = User::whereKey([$actor->id, $ownerId, ...$additionalIds])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $creator = $coaches->get($actor->id);
                abort_unless($creator, 403);
                Gate::forUser($creator)->authorize('create', Course::class);

                $coaches->load(['specialties' => fn ($query) => $query->whereKey($attributes['specialty_id'])]);
                if ($creator->role === UserRole::Coach && $creator->specialties->isEmpty()) {
                    throw ValidationException::withMessages([
                        'specialty_id' => ['Choose one of your assigned specialties.'],
                    ]);
                }

                $owner = $coaches->get($ownerId);
                if (! $owner || $owner->role !== UserRole::Coach || $owner->specialties->isEmpty()) {
                    throw ValidationException::withMessages([
                        'coach_id' => ['Choose a Coach assigned to the course specialty.'],
                    ]);
                }

                foreach ($additionalIds as $id) {
                    $additional = $coaches->get($id);
                    if ($id === $ownerId || ! $additional || $additional->role !== UserRole::Coach || $additional->specialties->isEmpty()) {
                        throw ValidationException::withMessages([
                            'additional_coach_ids' => ['Choose other Coaches assigned to the course specialty.'],
                        ]);
                    }
                }

                if (isset($attributes['cover'])) {
                    $coverPath = $attributes['cover']->store('courses/covers', 'local');
                    if (! is_string($coverPath) || $coverPath === '') {
                        throw new RuntimeException('Unable to store the course cover.');
                    }
                }

                $course = new Course;
                $course->title = $attributes['title'];
                $course->description = $attributes['description'];
                $course->price = $attributes['price'];
                $course->specialty_id = $attributes['specialty_id'];
                $course->coach_id = $ownerId;
                $course->status = $attributes['status'] ?? 'draft';
                $course->level = $attributes['level'] ?? null;
                $course->cover_path = $coverPath;
                $course->save();
                $course->additionalCoaches()->sync($additionalIds);

                return $course->load(['additionalCoaches' => fn ($query) => $query->orderBy('users.name')->orderBy('users.id')]);
            });
        } catch (Throwable $exception) {
            // Filesystem writes do not roll back with the database transaction.
            if (is_string($coverPath) && $coverPath !== '') {
                try {
                    if (! Storage::disk('local')->delete($coverPath)) {
                        report(new RuntimeException('Unable to clean up a failed course cover upload.'));
                    }
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }
    }
}

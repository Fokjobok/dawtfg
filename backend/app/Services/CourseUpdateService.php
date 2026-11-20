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
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class CourseUpdateService
{
    public function update(User $actor, Course $course, array $attributes): Course
    {
        $newCoverPath = null;
        $oldCoverPath = null;

        try {
            $updated = DB::transaction(function () use ($actor, $course, $attributes, &$newCoverPath, &$oldCoverPath): Course {
                $snapshot = Course::with('additionalCoaches:id')->findOrFail($course->id);
                $previousIds = $snapshot->additionalCoaches->modelKeys();
                sort($previousIds);
                $ownerId = (int) ($attributes['coach_id'] ?? $snapshot->coach_id);
                $additionalIds = array_map('intval', $attributes['additional_coach_ids'] ?? $previousIds);
                $userIds = array_filter([$actor->id, $snapshot->coach_id, $ownerId, ...$previousIds, ...$additionalIds]);
                // Share the ascending user-lock order used by creation and administrative edits.
                $users = User::whereKey($userIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $current = Course::lockForUpdate()->findOrFail($course->id);
                $currentIds = $current->additionalCoaches()->orderBy('users.id')->pluck('users.id')->all();
                if ((int) $current->coach_id !== (int) $snapshot->coach_id || $currentIds !== $previousIds) {
                    throw new ConflictHttpException('The teaching team changed. Reload the course and try again.');
                }

                $editor = $users->get($actor->id);
                abort_unless($editor, 403);
                Gate::forUser($editor)->authorize('update', $current);
                if ($ownerId !== (int) $current->coach_id && ! Gate::forUser($editor)->allows('changeResponsible', $current)) {
                    throw ValidationException::withMessages(['coach_id' => ['A collaborator cannot replace the responsible Coach.']]);
                }

                $specialtyId = (int) ($attributes['specialty_id'] ?? $current->specialty_id);
                $users->load(['specialties' => fn ($query) => $query->whereKey($specialtyId)]);
                if ($editor->role === UserRole::Coach && $editor->specialties->isEmpty()) {
                    throw ValidationException::withMessages(['specialty_id' => ['Choose one of your assigned specialties.']]);
                }
                $owner = $users->get($ownerId);
                if (! $owner || $owner->role !== UserRole::Coach || $owner->specialties->isEmpty()) {
                    throw ValidationException::withMessages(['coach_id' => ['Choose a Coach assigned to the course specialty.']]);
                }
                foreach ($additionalIds as $id) {
                    $additional = $users->get($id);
                    if ($id === $ownerId || ! $additional || $additional->role !== UserRole::Coach || $additional->specialties->isEmpty()) {
                        throw ValidationException::withMessages(['additional_coach_ids' => ['Choose other Coaches assigned to the course specialty.']]);
                    }
                }

                foreach (['title', 'description', 'price', 'status', 'level'] as $field) {
                    if (array_key_exists($field, $attributes)) {
                        $current->{$field} = $attributes[$field];
                    }
                }
                $current->coach_id = $ownerId;
                $current->specialty_id = $specialtyId;
                if (isset($attributes['cover'])) {
                    $newCoverPath = $attributes['cover']->store('courses/covers', 'local');
                    if (! is_string($newCoverPath) || $newCoverPath === '') {
                        throw new RuntimeException('Unable to store the course cover.');
                    }
                    $oldCoverPath = $current->cover_path;
                    $current->cover_path = $newCoverPath;
                } elseif ($attributes['remove_cover'] ?? false) {
                    $oldCoverPath = $current->cover_path;
                    $current->cover_path = null;
                }
                $current->save();
                $current->additionalCoaches()->sync($additionalIds);

                return $current->load(['coach:id,name', 'specialty:id,name', 'additionalCoaches' => fn ($query) => $query->orderBy('users.name')->orderBy('users.id')]);
            });
        } catch (Throwable $exception) {
            if (is_string($newCoverPath) && $newCoverPath !== '') {
                $this->deleteCover($newCoverPath);
            }
            throw $exception;
        }

        // The original file stays available until the database change has committed.
        if (is_string($oldCoverPath) && $oldCoverPath !== '') {
            $this->deleteCover($oldCoverPath);
        }

        return $updated;
    }

    private function deleteCover(string $path): void
    {
        try {
            if (! Storage::disk('local')->delete($path)) {
                report(new RuntimeException('Unable to clean up a course cover.'));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

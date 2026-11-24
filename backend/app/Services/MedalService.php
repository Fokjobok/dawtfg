<?php

namespace App\Services;

use App\Models\Medal;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class MedalService
{
    public function create(User $actor, array $attributes): Medal
    {
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $attributes, &$path): Medal {
                $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($current)->authorize('create', Medal::class);
                $medal = new Medal(['name' => $attributes['name'], 'specialty_id' => $attributes['specialty_id']]);
                if (isset($attributes['image'])) {
                    $path = $attributes['image']->store('medals/images', 'local');
                    if (! is_string($path) || $path === '') {
                        throw new RuntimeException('Unable to store the medal image.');
                    }
                    $medal->image_path = $path;
                }
                $medal->save();

                return $medal->load('specialty');
            });
        } catch (Throwable $exception) {
            if (is_string($path) && $path !== '') {
                try {
                    if (! Storage::disk('local')->delete($path)) {
                        report(new RuntimeException('Unable to clean up a medal image.'));
                    }
                } catch (Throwable $cleanupError) {
                    report($cleanupError);
                }
            }
            if ($exception instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['name' => ['This medal already exists in this specialty.']]);
            }
            throw $exception;
        }
    }

    public function assign(User $actor, User $user, Medal $medal, bool $remove = false): void
    {
        DB::transaction(function () use ($actor, $user, $medal, $remove): void {
            // Stable user lock order serializes assignments and concurrent role changes.
            $users = User::whereIn('id', [$actor->id, $user->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($users->has($actor->id) && $users->has($user->id), 404);
            Gate::forUser($users[$actor->id])->authorize('assign', Medal::class);
            $current = Medal::whereKey($medal->id)->lockForUpdate()->firstOrFail();
            if ($remove) {
                $users[$user->id]->medals()->detach($current->id);
            } elseif (! $users[$user->id]->medals()->whereKey($current->id)->exists()) {
                // Repeating a PUT preserves the original assignment and its timestamps.
                $users[$user->id]->medals()->attach($current->id);
            }
        });
    }
}

<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProfileUpdateService
{
    public function update(User $actor, array $attributes): User
    {
        $newAvatarPath = null;
        $oldAvatarPath = null;

        try {
            $updated = DB::transaction(function () use ($actor, $attributes, &$newAvatarPath, &$oldAvatarPath): User {
                $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($current)->authorize('updateProfile', $current);
                // Profile edits never accept account roles, credentials or specialty assignments.
                foreach (['name', 'bio', 'is_private', 'bio_is_private'] as $field) {
                    if (array_key_exists($field, $attributes)) {
                        $current->{$field} = $attributes[$field];
                    }
                }
                if (isset($attributes['avatar'])) {
                    $newAvatarPath = $attributes['avatar']->store('profiles/avatars', 'local');
                    if (! is_string($newAvatarPath) || $newAvatarPath === '') {
                        throw new RuntimeException('Unable to store the profile avatar.');
                    }
                    $oldAvatarPath = $current->avatar_path;
                    $current->avatar_path = $newAvatarPath;
                } elseif ($attributes['remove_avatar'] ?? false) {
                    $oldAvatarPath = $current->avatar_path;
                    $current->avatar_path = null;
                }
                if (! $current->save()) {
                    throw new RuntimeException('Unable to update the profile.');
                }

                return $current;
            });
        } catch (Throwable $exception) {
            if (is_string($newAvatarPath) && $newAvatarPath !== '') {
                $this->deleteAvatar($newAvatarPath);
            }
            throw $exception;
        }

        if (is_string($oldAvatarPath) && $oldAvatarPath !== '') {
            $this->deleteAvatar($oldAvatarPath);
        }

        return $updated;
    }

    private function deleteAvatar(string $path): void
    {
        try {
            if (! Storage::disk('local')->delete($path)) {
                report(new RuntimeException('Unable to clean up a profile avatar.'));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

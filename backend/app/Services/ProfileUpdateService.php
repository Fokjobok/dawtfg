<?php

namespace App\Services;

use App\Exceptions\ProfileUpdateCooldownException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ProfileUpdateService
{
    /** @return array{profile: User, updated: bool} */
    public function update(User $actor, array $attributes): array
    {
        $newAvatarPath = null;
        $oldAvatarPath = null;

        try {
            $updated = DB::transaction(function () use ($actor, $attributes, &$newAvatarPath, &$oldAvatarPath): array {
                $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($current)->authorize('updateProfile', $current);
                // Profile edits never accept account roles, credentials or specialty assignments.
                foreach (['name', 'bio', 'is_private', 'bio_is_private', 'enrollments_is_private', 'medals_is_private'] as $field) {
                    if (array_key_exists($field, $attributes)) {
                        $current->{$field} = $attributes[$field];
                    }
                }
                $currentFeatured = $current->enrollments()->whereNotNull('featured_order')
                    ->orderBy('featured_order')->orderBy('course_id')->pluck('course_id')->map(fn ($id) => (int) $id)->all();
                $requestedFeatured = array_key_exists('featured_course_ids', $attributes)
                    ? array_map('intval', $attributes['featured_course_ids']) : $currentFeatured;
                if (array_key_exists('featured_course_ids', $attributes)) {
                    $ownedIds = $current->enrollments()->whereIn('course_id', $requestedFeatured)->lockForUpdate()->pluck('course_id');
                    if ($ownedIds->count() !== count($requestedFeatured)) {
                        throw ValidationException::withMessages(['featured_course_ids' => ['Choose only courses you are enrolled in.']]);
                    }
                }
                $featuresChanged = $requestedFeatured !== $currentFeatured;
                $replaceAvatar = isset($attributes['avatar']) && ! $this->hasSameAvatar($current, $attributes['avatar']->getRealPath());
                $removeAvatar = ($attributes['remove_avatar'] ?? false) && $current->avatar_path !== null;
                if (! $current->isDirty() && ! $replaceAvatar && ! $removeAvatar && ! $featuresChanged) {
                    return ['profile' => $current, 'updated' => false];
                }

                // Row locking serializes saves across sessions; rejected requests never reset the limit.
                $now = now()->startOfSecond();
                if ($current->profile_save_available_at?->isAfter($now)) {
                    throw new ProfileUpdateCooldownException((int) ceil($now->diffInSeconds($current->profile_save_available_at)));
                }
                if ($current->profile_save_available_at !== null) {
                    $current->profile_save_count = 0;
                    $current->profile_save_available_at = null;
                }

                if ($replaceAvatar) {
                    $newAvatarPath = $attributes['avatar']->store('profiles/avatars', 'local');
                    if (! is_string($newAvatarPath) || $newAvatarPath === '') {
                        throw new RuntimeException('Unable to store the profile avatar.');
                    }
                    $oldAvatarPath = $current->avatar_path;
                    $current->avatar_path = $newAvatarPath;
                } elseif ($removeAvatar) {
                    $oldAvatarPath = $current->avatar_path;
                    $current->avatar_path = null;
                }
                if ($featuresChanged) {
                    $current->enrollments()->whereNotNull('featured_order')->update(['featured_order' => null]);
                    foreach ($requestedFeatured as $order => $id) {
                        $current->enrollments()->where('course_id', $id)->update(['featured_order' => $order + 1]);
                    }
                }
                $current->profile_save_count++;
                if ($current->profile_save_count >= 3) {
                    $current->profile_save_available_at = $now->copy()->addSeconds(60);
                }
                if (! $current->save()) {
                    throw new RuntimeException('Unable to update the profile.');
                }

                return ['profile' => $current, 'updated' => true];
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

    private function hasSameAvatar(User $user, string $replacement): bool
    {
        if (! $user->avatar_path || ! Storage::disk('local')->exists($user->avatar_path)) {
            return false;
        }

        return hash_equals(hash('sha256', Storage::disk('local')->get($user->avatar_path)), hash_file('sha256', $replacement));
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

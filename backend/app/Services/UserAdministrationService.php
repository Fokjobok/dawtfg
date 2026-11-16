<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserAdministrationService
{
    public function update(User $actor, User $target, array $attributes): User
    {
        try {
            return DB::transaction(function () use ($actor, $target, $attributes): User {
                $user = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
                $role = isset($attributes['role']) ? UserRole::from($attributes['role']) : $user->role;
                $specialties = $attributes['specialty_ids'] ?? $user->specialties()->pluck('specialties.id')->all();

                if ($user->is($actor) && $role !== UserRole::Administrator) {
                    throw ValidationException::withMessages(['role' => ['You cannot remove your own Administrator role.']]);
                }

                if ($role !== UserRole::Coach && ! empty($attributes['specialty_ids'])) {
                    throw ValidationException::withMessages(['specialty_ids' => ['Only Coaches can have specialties.']]);
                }

                if ($role !== UserRole::Coach && Course::where('coach_id', $user->id)->exists()) {
                    throw ValidationException::withMessages(['role' => ['Reassign this Coach’s courses before changing the role.']]);
                }

                if ($role === UserRole::Coach && Course::where('coach_id', $user->id)->whereNotNull('specialty_id')->whereNotIn('specialty_id', $specialties)->exists()) {
                    throw ValidationException::withMessages(['specialty_ids' => ['A specialty used by this Coach’s courses cannot be removed.']]);
                }

                $user->fill(array_intersect_key($attributes, array_flip(['name', 'email'])));
                if (array_key_exists('email', $attributes) && $user->isDirty('email')) {
                    $user->email_verified_at = null;
                }
                // Role remains excluded from public mass assignment.
                $user->role = $role;
                $user->save();
                $user->specialties()->sync($role === UserRole::Coach ? $specialties : []);

                return $user->load('specialties');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => ['This email is already registered.']]);
        }
    }
}

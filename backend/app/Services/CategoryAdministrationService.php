<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CategoryAdministrationService
{
    public function create(User $actor, array $attributes): Category
    {
        try {
            return DB::transaction(function () use ($actor, $attributes): Category {
                $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($current)->authorize('create', Category::class);

                return Category::create(['name' => $attributes['name']]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['This category already exists.']]);
        }
    }

    public function classify(User $actor, Specialty $specialty, ?int $categoryId): Specialty
    {
        return DB::transaction(function () use ($actor, $specialty, $categoryId): Specialty {
            $currentActor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($currentActor)->authorize('updateCategory', $specialty);
            $current = Specialty::whereKey($specialty->id)->lockForUpdate()->firstOrFail();
            $current->category_id = $categoryId;
            if ($current->isDirty()) {
                $current->save();
            }

            return $current->load('category');
        });
    }
}

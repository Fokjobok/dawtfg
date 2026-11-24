<?php

namespace App\Models;

use App\Enums\CourseLevel;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    public function scopeManagedBy(Builder $query, User $actor): Builder
    {
        if ($actor->role === UserRole::Administrator) {
            return $query;
        }

        if ($actor->role !== UserRole::Coach) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn ($courses) => $courses->where('coach_id', $actor->id)
            ->orWhereHas('additionalCoaches', fn ($coaches) => $coaches->whereKey($actor->id)));
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        // Legacy nullable assignments do not make a course ready for the public catalog.
        return $query->where('status', 'published')->whereHas('specialty')
            ->whereHas('coach', fn ($coach) => $coach->where('role', UserRole::Coach->value)
                ->whereHas('specialties', fn ($specialty) => $specialty->whereColumn('specialties.id', 'courses.specialty_id')));
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function additionalCoaches(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_coach', 'course_id', 'coach_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'level' => CourseLevel::class];
    }
}

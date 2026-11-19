<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Course extends Model
{
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

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }
}

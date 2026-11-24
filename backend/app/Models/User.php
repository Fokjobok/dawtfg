<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'avatar_path', 'bio', 'bio_is_private', 'enrollments_is_private', 'medals_is_private', 'profile_save_count', 'profile_save_available_at'])]
class User extends Authenticatable
{
    use Notifiable;

    protected $attributes = ['role' => UserRole::Student->value];

    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class, 'coach_specialty', 'coach_id', 'specialty_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function medals(): BelongsToMany
    {
        return $this->belongsToMany(Medal::class)->withTimestamps();
    }

    public function enrolledCourses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'enrollments')
            ->withPivot(['id', 'featured_order'])->withTimestamps();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_private' => 'boolean',
            'bio_is_private' => 'boolean',
            'enrollments_is_private' => 'boolean',
            'medals_is_private' => 'boolean',
            'level' => 'integer',
            'profile_save_count' => 'integer',
            'profile_save_available_at' => 'immutable_datetime',
        ];
    }
}

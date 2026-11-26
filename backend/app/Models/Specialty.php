<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'category_id'])]
class Specialty extends Model
{
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function coaches(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'coach_specialty', 'specialty_id', 'coach_id')
            ->where('users.role', UserRole::Coach->value);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function medals(): HasMany
    {
        return $this->hasMany(Medal::class);
    }
}

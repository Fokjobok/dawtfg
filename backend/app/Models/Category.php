<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class Category extends Model
{
    // Avoid names retained by the historical categories -> specialties migration.
    protected $table = 'specialty_categories';

    public function specialties(): HasMany
    {
        return $this->hasMany(Specialty::class);
    }
}

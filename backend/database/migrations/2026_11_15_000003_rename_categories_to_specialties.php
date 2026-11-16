<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('categories', 'specialties');
        Schema::table('courses', fn (Blueprint $table) => $table->renameColumn('category_id', 'specialty_id'));

        // Native renames preserve existing references and column-level CHECKs.
        // PostgreSQL retains constraint names, so align the FK with the new column.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE courses RENAME CONSTRAINT courses_category_id_foreign TO courses_specialty_id_foreign');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE courses RENAME CONSTRAINT courses_specialty_id_foreign TO courses_category_id_foreign');
        }

        Schema::table('courses', fn (Blueprint $table) => $table->renameColumn('specialty_id', 'category_id'));
        Schema::rename('specialties', 'categories');
    }
};

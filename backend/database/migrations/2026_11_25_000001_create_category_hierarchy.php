<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialty_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });
        // An inline FK lets SQLite remove this column without rebuilding a table referenced by courses.
        // PostgreSQL supports the same SQL; existing specialties stay unclassified until an admin assigns them.
        DB::statement('ALTER TABLE specialties ADD COLUMN category_id BIGINT NULL REFERENCES specialty_categories(id) ON DELETE RESTRICT');
        DB::statement('CREATE INDEX specialties_category_id_index ON specialties (category_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX specialties_category_id_index');
        DB::statement('ALTER TABLE specialties DROP COLUMN category_id');
        Schema::dropIfExists('specialty_categories');
    }
};

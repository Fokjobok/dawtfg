<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            // Preserve existing courses without inventing a coach or category.
            $table->foreignId('coach_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('status', ['draft', 'published'])->default('draft');
        });

        // A column-level CHECK works on PostgreSQL and SQLite, unlike ALTER ADD CONSTRAINT.
        DB::statement('ALTER TABLE courses ADD COLUMN price DECIMAL(10, 2) NOT NULL DEFAULT 0 CONSTRAINT courses_price_nonnegative CHECK (price >= 0)');
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropForeign(['coach_id']);
            $table->dropForeign(['category_id']);
            $table->dropColumn(['coach_id', 'category_id', 'status', 'price']);
        });
    }
};

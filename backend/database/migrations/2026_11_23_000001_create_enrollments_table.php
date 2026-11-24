<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'course_id']);
            $table->index('course_id');
        });
        // Range + uniqueness allow at most three featured positions per user, also in SQL.
        DB::statement('ALTER TABLE enrollments ADD COLUMN featured_order SMALLINT NULL CONSTRAINT enrollments_featured_order_range CHECK (featured_order BETWEEN 1 AND 3)');
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->unique(['user_id', 'featured_order']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('enrollments_is_private')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('enrollments_is_private');
        });
        Schema::dropIfExists('enrollments');
    }
};

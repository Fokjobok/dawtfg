<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_coach', function (Blueprint $table): void {
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coach_id')->constrained('users')->restrictOnDelete();
            $table->primary(['course_id', 'coach_id']);
            $table->index('coach_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_coach');
    }
};

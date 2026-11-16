<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_specialty', function (Blueprint $table): void {
            $table->foreignId('coach_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('specialty_id')->constrained('specialties')->cascadeOnDelete();
            $table->primary(['coach_id', 'specialty_id']);
            $table->index('specialty_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_specialty');
    }
};

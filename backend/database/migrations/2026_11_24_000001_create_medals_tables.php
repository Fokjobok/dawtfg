<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medals', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('specialty_id')->constrained()->restrictOnDelete();
            $table->string('image_path')->nullable();
            $table->timestamps();
            $table->unique(['specialty_id', 'name']);
        });
        Schema::create('medal_user', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medal_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'medal_id']);
            $table->index('medal_id');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('medals_is_private')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('medals_is_private'));
        Schema::dropIfExists('medal_user');
        Schema::dropIfExists('medals');
    }
};

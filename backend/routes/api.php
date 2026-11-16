<?php

use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\SpecialtyController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('api.v1.health');
    Route::get('user', CurrentUserController::class)->middleware('auth:sanctum')->name('api.v1.user');
    Route::get('users', [UserController::class, 'index'])->middleware('auth:sanctum')->name('api.v1.users.index');
    Route::get('specialties', [SpecialtyController::class, 'index'])->middleware('auth:sanctum')->name('api.v1.specialties.index');
});

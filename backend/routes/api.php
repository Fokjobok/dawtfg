<?php

use App\Http\Controllers\Api\V1\CatalogSpecialtyController;
use App\Http\Controllers\Api\V1\CourseController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\SpecialtyController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('api.v1.health');
    Route::get('courses', [CourseController::class, 'index'])->name('api.v1.courses.index');
    Route::get('catalog/specialties', CatalogSpecialtyController::class)->name('api.v1.catalog.specialties');
    Route::get('teaching/courses', [CourseController::class, 'managed'])->middleware('auth:sanctum')->name('api.v1.courses.managed');
    Route::get('courses/{course}', [CourseController::class, 'show'])->whereNumber('course')->middleware('auth:sanctum')->name('api.v1.courses.show');
    Route::get('courses/{course}/cover', [CourseController::class, 'cover'])->whereNumber('course')->name('api.v1.courses.cover');
    Route::get('user', CurrentUserController::class)->middleware('auth:sanctum')->name('api.v1.user');
    Route::get('users', [UserController::class, 'index'])->middleware('auth:sanctum')->name('api.v1.users.index');
    Route::get('specialties', [SpecialtyController::class, 'index'])->middleware('auth:sanctum')->name('api.v1.specialties.index');
    Route::get('specialties/{specialty}/coaches', [SpecialtyController::class, 'coaches'])->whereNumber('specialty')->middleware('auth:sanctum')->name('api.v1.specialties.coaches');
});

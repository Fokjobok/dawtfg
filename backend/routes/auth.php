<?php

use App\Http\Controllers\Api\V1\CourseController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RegisterController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SpecialtyController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

// The web group resolves SPA sessions even without Origin and protects mutations with CSRF.
Route::prefix('api/v1')->group(function (): void {
    Route::get('session', [SessionController::class, 'show'])->name('api.v1.session.show');
    // Optional web sessions let owners read their own private details without relying on Origin.
    Route::get('profiles/{user}', [ProfileController::class, 'show'])->whereNumber('user')->name('api.v1.profiles.show');
    Route::get('profile', [ProfileController::class, 'current'])->middleware('auth:sanctum')->name('api.v1.profile.current');
    Route::patch('profile', [ProfileController::class, 'update'])->middleware('auth:sanctum')->name('api.v1.profile.update');
    Route::post('register', RegisterController::class)->middleware('throttle:registration')->name('api.v1.register');
    Route::post('login', [SessionController::class, 'store'])->middleware('throttle:login')->name('api.v1.login');
    Route::post('logout', [SessionController::class, 'destroy'])->middleware('auth:sanctum')->name('api.v1.logout');
    // Admin mutations always receive session and CSRF middleware, including without Origin.
    Route::patch('users/{user}', [UserController::class, 'update'])->whereNumber('user')->middleware('auth:sanctum')->name('api.v1.users.update');
    Route::post('specialties', [SpecialtyController::class, 'store'])->middleware('auth:sanctum')->name('api.v1.specialties.store');
    Route::post('courses', [CourseController::class, 'store'])->middleware('auth:sanctum')->name('api.v1.courses.store');
    Route::patch('courses/{course}', [CourseController::class, 'update'])->whereNumber('course')->middleware('auth:sanctum')->name('api.v1.courses.update');
    Route::delete('courses/{course}', [CourseController::class, 'destroy'])->whereNumber('course')->middleware('auth:sanctum')->name('api.v1.courses.destroy');
});

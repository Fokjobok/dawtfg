<?php

use App\Http\Controllers\Api\V1\CourseController;
use App\Http\Controllers\Api\V1\EnrollmentController;
use App\Http\Controllers\Api\V1\MedalController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RegisterController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SpecialtyController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

// The web group resolves SPA sessions even without Origin and protects mutations with CSRF.
Route::prefix('api/v1')->group(function (): void {
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('medals', [MedalController::class, 'index'])->name('api.v1.medals.index');
        Route::get('medal-recipients', [MedalController::class, 'recipients'])->name('api.v1.medals.recipients');
        Route::post('medals', [MedalController::class, 'store'])->name('api.v1.medals.store');
        Route::get('users/{user}/medals', [MedalController::class, 'userMedals'])->whereNumber('user')->name('api.v1.users.medals.index');
        Route::put('users/{user}/medals/{medal}', [MedalController::class, 'assign'])->whereNumber(['user', 'medal'])->name('api.v1.users.medals.assign');
        Route::delete('users/{user}/medals/{medal}', [MedalController::class, 'revoke'])->whereNumber(['user', 'medal'])->name('api.v1.users.medals.revoke');
    });
    Route::get('enrollments', [EnrollmentController::class, 'index'])->middleware('auth:sanctum')->name('api.v1.enrollments.index');
    Route::get('enrollments/{enrollment}', [EnrollmentController::class, 'show'])->whereNumber('enrollment')->middleware('auth:sanctum')->name('api.v1.enrollments.show');
    Route::post('courses/{course}/enrollments', [EnrollmentController::class, 'store'])->whereNumber('course')->middleware('auth:sanctum')->name('api.v1.enrollments.store');
    Route::delete('enrollments/{enrollment}', [EnrollmentController::class, 'destroy'])->whereNumber('enrollment')->middleware('auth:sanctum')->name('api.v1.enrollments.destroy');
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

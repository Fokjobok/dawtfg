<?php

use App\Http\Controllers\Api\V1\RegisterController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SpecialtyController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

// The web group gives every authentication mutation a session and CSRF protection.
Route::prefix('api/v1')->group(function (): void {
    Route::post('register', RegisterController::class)->middleware('throttle:registration')->name('api.v1.register');
    Route::post('login', [SessionController::class, 'store'])->middleware('throttle:login')->name('api.v1.login');
    Route::post('logout', [SessionController::class, 'destroy'])->middleware('auth:sanctum')->name('api.v1.logout');
    // Admin mutations always receive session and CSRF middleware, including without Origin.
    Route::patch('users/{user}', [UserController::class, 'update'])->whereNumber('user')->middleware('auth:sanctum')->name('api.v1.users.update');
    Route::post('specialties', [SpecialtyController::class, 'store'])->middleware('auth:sanctum')->name('api.v1.specialties.store');
});

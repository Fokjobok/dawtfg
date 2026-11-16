<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class RegistrationService
{
    public function register(array $attributes): User
    {
        try {
            return User::create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent registration may claim the email after request validation.
            throw ValidationException::withMessages(['email' => ['This email is already registered.']]);
        }
    }
}

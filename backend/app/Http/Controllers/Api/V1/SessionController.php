<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class SessionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user('web');
        if ($user) {
            Gate::forUser($user)->authorize('view', $user);
        }

        // An anonymous visitor is a normal state of this optional session query.
        return response()->json([
            'data' => $user ? (new UserResource($user))->resolve($request) : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(LoginRequest $request): UserResource
    {
        if (! Auth::guard('web')->attempt($request->validated())) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        $request->session()->regenerate();

        return new UserResource($request->user());
    }

    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;
use App\Models\User;
use App\Services\ProfileUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    public function show(Request $request, User $user): JsonResponse
    {
        return (new ProfileResource($user))->response()->header('Cache-Control', 'no-store, private');
    }

    public function current(Request $request): JsonResponse
    {
        return (new ProfileResource($request->user('web')))->response()->header('Cache-Control', 'no-store, private');
    }

    public function update(UpdateProfileRequest $request, ProfileUpdateService $profiles): JsonResponse
    {
        $updated = $profiles->update($request->user('web'), $request->validated());

        return (new ProfileResource($updated['profile']))->additional([
            'meta' => ['updated' => $updated['updated']],
        ])
            ->response()->header('Cache-Control', 'no-store, private');
    }

    public function avatar(User $user): StreamedResponse
    {
        abort_unless($user->avatar_path && Storage::disk('local')->exists($user->avatar_path), 404);

        return Storage::disk('local')->response($user->avatar_path, null, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], 'inline');
    }
}

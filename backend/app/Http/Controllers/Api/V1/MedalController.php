<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListMedalsRequest;
use App\Http\Requests\StoreMedalRequest;
use App\Http\Resources\MedalRecipientResource;
use App\Http\Resources\MedalResource;
use App\Models\Medal;
use App\Models\User;
use App\Services\MedalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MedalController extends Controller
{
    public function index(ListMedalsRequest $request): JsonResponse
    {
        $input = $request->validated();

        return MedalResource::collection(Medal::with('specialty')
            ->when($input['filter']['specialty_id'] ?? null, fn ($query, $id) => $query->where('specialty_id', $id))
            ->orderBy('name')->orderBy('id')->paginate($input['per_page'] ?? 20)->withQueryString())
            ->response()->header('Cache-Control', 'no-store, private');
    }

    public function store(StoreMedalRequest $request, MedalService $service): JsonResponse
    {
        return (new MedalResource($service->create($request->user('web'), $request->validated())))
            ->response()->setStatusCode(201)->header('Cache-Control', 'no-store, private');
    }

    public function recipients(ListMedalsRequest $request): JsonResponse
    {
        $input = $request->validated();

        return MedalRecipientResource::collection(User::query()
            ->when($input['filter']['name'] ?? null, fn ($query, $name) => $query->whereLike('name', '%'.addcslashes($name, '%_\\').'%'))
            ->orderBy('name')->orderBy('id')->paginate($input['per_page'] ?? 20)->withQueryString())
            ->response()->header('Cache-Control', 'no-store, private');
    }

    public function userMedals(ListMedalsRequest $request, User $user): JsonResponse
    {
        $input = $request->validated();

        return MedalResource::collection($user->medals()->with('specialty')->orderBy('medals.name')->orderBy('medals.id')
            ->when($input['filter']['specialty_id'] ?? null, fn ($query, $id) => $query->where('medals.specialty_id', $id))
            ->paginate($input['per_page'] ?? 20)->withQueryString())
            ->response()->header('Cache-Control', 'no-store, private');
    }

    public function assign(Request $request, User $user, Medal $medal, MedalService $service): JsonResponse
    {
        Gate::forUser($request->user('web'))->authorize('assign', Medal::class);
        $service->assign($request->user('web'), $user, $medal);

        return (new MedalResource($medal->load('specialty')))->response()->header('Cache-Control', 'no-store, private');
    }

    public function revoke(Request $request, User $user, Medal $medal, MedalService $service): Response
    {
        Gate::forUser($request->user('web'))->authorize('assign', Medal::class);
        $service->assign($request->user('web'), $user, $medal, remove: true);

        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }

    public function image(Medal $medal): StreamedResponse
    {
        // Medal artwork is a public catalog asset, never a list of its recipients.
        abort_unless($medal->image_path && Storage::disk('local')->exists($medal->image_path), 404);

        return Storage::disk('local')->response($medal->image_path, null, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], 'inline');
    }
}

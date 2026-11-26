<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListSpecialtiesRequest;
use App\Http\Requests\ListSpecialtyCoachesRequest;
use App\Http\Requests\StoreSpecialtyRequest;
use App\Http\Requests\UpdateSpecialtyCategoryRequest;
use App\Http\Resources\CategorizedSpecialtyResource;
use App\Http\Resources\CoachResource;
use App\Models\Specialty;
use App\Services\CategoryAdministrationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class SpecialtyController extends Controller
{
    public function index(ListSpecialtiesRequest $request): JsonResponse
    {
        $input = $request->validated();
        $specialties = $request->user()->role === UserRole::Coach
            ? $request->user()->specialties()
            : Specialty::query();

        return CategorizedSpecialtyResource::collection($specialties->with('category')->orderBy('name')->orderBy('specialties.id')
            ->paginate($input['per_page'] ?? 20)->withQueryString())
            ->response()->header('Cache-Control', 'no-store, private');
    }

    public function store(StoreSpecialtyRequest $request): JsonResponse
    {
        try {
            $specialty = Specialty::create($request->validated());
        } catch (UniqueConstraintViolationException) {
            // A concurrent request can claim the name after validation.
            throw ValidationException::withMessages(['name' => ['This specialty already exists.']]);
        }

        // Creating the catalog entry does not assign it to any user.
        return (new CategorizedSpecialtyResource($specialty->load('category')))->response()
            ->setStatusCode(201)->header('Cache-Control', 'no-store, private');
    }

    public function coaches(ListSpecialtyCoachesRequest $request, Specialty $specialty): JsonResponse
    {
        $input = $request->validated();
        $coaches = $specialty->coaches();

        return CoachResource::collection($coaches->orderBy('users.name')->orderBy('users.id')
            ->paginate($input['per_page'] ?? 20)->withQueryString())
            ->response()->header('Cache-Control', 'no-store, private');
    }

    public function updateCategory(UpdateSpecialtyCategoryRequest $request, Specialty $specialty, CategoryAdministrationService $service): JsonResponse
    {
        $input = $request->validated();

        return (new CategorizedSpecialtyResource($service->classify($request->user('web'), $specialty, $input['category_id'])))
            ->response()->header('Cache-Control', 'no-store, private');
    }
}

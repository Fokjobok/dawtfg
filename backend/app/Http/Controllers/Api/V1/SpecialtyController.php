<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListSpecialtiesRequest;
use App\Http\Requests\StoreSpecialtyRequest;
use App\Http\Resources\SpecialtyResource;
use App\Models\Specialty;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class SpecialtyController extends Controller
{
    public function index(ListSpecialtiesRequest $request): JsonResponse
    {
        $input = $request->validated();

        return SpecialtyResource::collection(Specialty::orderBy('name')->orderBy('id')
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
        return (new SpecialtyResource($specialty))->response()
            ->setStatusCode(201)->header('Cache-Control', 'no-store, private');
    }
}

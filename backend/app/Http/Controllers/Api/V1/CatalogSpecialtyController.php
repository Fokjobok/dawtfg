<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListCatalogSpecialtiesRequest;
use App\Http\Resources\CategorizedSpecialtyResource;
use App\Models\Specialty;
use Illuminate\Http\JsonResponse;

class CatalogSpecialtyController extends Controller
{
    public function __invoke(ListCatalogSpecialtiesRequest $request): JsonResponse
    {
        $input = $request->validated();

        return CategorizedSpecialtyResource::collection(Specialty::with('category')->whereHas('courses', fn ($courses) => $courses->publiclyVisible())
            ->when(isset($input['filter']['category_id']), fn ($query) => $query->where('category_id', $input['filter']['category_id']))
            ->orderBy('name')->orderBy('id')->paginate($input['per_page'] ?? 20)->withQueryString())
            ->response()->header('Cache-Control', 'no-store');
    }
}

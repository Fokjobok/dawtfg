<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListCategoriesRequest;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\CategoryAdministrationService;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(ListCategoriesRequest $request): JsonResponse
    {
        return $this->listing($request, public: false);
    }

    public function catalog(ListCategoriesRequest $request): JsonResponse
    {
        return $this->listing($request, public: true);
    }

    public function store(StoreCategoryRequest $request, CategoryAdministrationService $service): JsonResponse
    {
        return (new CategoryResource($service->create($request->user('web'), $request->validated())))
            ->response()->setStatusCode(201)->header('Cache-Control', 'no-store, private');
    }

    private function listing(ListCategoriesRequest $request, bool $public): JsonResponse
    {
        return CategoryResource::collection(Category::query()
            ->when($public, fn ($query) => $query->whereHas('specialties.courses', fn ($courses) => $courses->publiclyVisible()))
            ->orderBy('name')->orderBy('id')->paginate($request->validated('per_page', 20))->withQueryString())
            ->response()->header('Cache-Control', $public ? 'no-store' : 'no-store, private');
    }
}

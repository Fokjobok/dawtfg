<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListUsersRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use App\Services\UserAdministrationService;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function index(ListUsersRequest $request): JsonResponse
    {
        $input = $request->validated();
        $sort = $input['sort'] ?? 'id';
        $column = ltrim($sort, '-');
        $query = User::with('specialties')->where('role', '!=', UserRole::Administrator->value)->when(
            $input['filter']['role'] ?? null,
            fn ($query, $role) => $query->where('role', $role),
        )->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc');

        if ($column !== 'id') {
            $query->orderBy('id');
        }

        return AdminUserResource::collection($query->paginate($input['per_page'] ?? 20)->withQueryString())
            ->response()->header('Cache-Control', 'no-store, private');
    }

    public function update(UpdateUserRequest $request, User $user, UserAdministrationService $service): AdminUserResource
    {
        return new AdminUserResource($service->update($request->user(), $user, $request->validated()));
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListManagedCoursesRequest;
use App\Http\Requests\ListPublicCoursesRequest;
use App\Http\Requests\StoreCourseRequest;
use App\Http\Requests\UpdateCourseRequest;
use App\Http\Resources\CourseResource;
use App\Http\Resources\ManagedCourseResource;
use App\Http\Resources\PublicCourseResource;
use App\Models\Course;
use App\Services\CourseCreationService;
use App\Services\CourseDeletionService;
use App\Services\CourseUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CourseController extends Controller
{
    public function index(ListPublicCoursesRequest $request): JsonResponse
    {
        $input = $request->validated();
        $filters = $input['filter'] ?? [];

        return PublicCourseResource::collection(Course::publiclyVisible()
            ->when(isset($filters['specialty_id']), fn ($query) => $query->where('specialty_id', $filters['specialty_id']))
            ->when(isset($filters['level']), fn ($query) => $query->where('level', $filters['level']))
            ->with(['specialty:id,name', 'coach:id,name,avatar_path,is_private,medals_is_private', 'coach.medals.specialty:id,name'])->orderByDesc('id')
            ->paginate($input['per_page'] ?? 20)->withQueryString())
            ->response()->header('Cache-Control', 'no-store');
    }

    public function store(StoreCourseRequest $request, CourseCreationService $courses): JsonResponse
    {
        $course = $courses->create($request->user(), $request->validated());

        return (new CourseResource($course))->response()
            ->setStatusCode(201)->header('Location', route('api.v1.courses.show', ['course' => $course->id], false))
            ->header('Cache-Control', 'no-store, private');
    }

    public function managed(ListManagedCoursesRequest $request): JsonResponse
    {
        $input = $request->validated();

        return ManagedCourseResource::collection(Course::managedBy($request->user())
            ->with(['coach:id,name', 'specialty:id,name', 'additionalCoaches' => fn ($query) => $query->orderBy('users.name')->orderBy('users.id')])
            ->orderByDesc('id')->paginate($input['per_page'] ?? 20)->withQueryString())
            ->response()->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('view', $course);
        $course->load(['coach:id,name', 'specialty:id,name', 'additionalCoaches' => fn ($query) => $query->orderBy('users.name')->orderBy('users.id')]);

        return (new ManagedCourseResource($course))->response()->header('Cache-Control', 'no-store, private');
    }

    public function update(UpdateCourseRequest $request, Course $course, CourseUpdateService $courses): JsonResponse
    {
        return (new ManagedCourseResource($courses->update($request->user(), $course, $request->validated())))
            ->response()->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request, Course $course, CourseDeletionService $courses): Response
    {
        Gate::authorize('delete', $course);
        $courses->delete($request->user(), $course);

        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }

    public function cover(Request $request, Course $course): StreamedResponse
    {
        abort_unless(Gate::allows('viewCover', $course), $request->user() ? 403 : 401);
        abort_unless($course->cover_path && Storage::disk('local')->exists($course->cover_path), 404);

        return Storage::disk('local')->response($course->cover_path, null, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], 'inline');
    }
}

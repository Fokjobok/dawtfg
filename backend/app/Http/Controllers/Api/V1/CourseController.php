<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListPublicCoursesRequest;
use App\Http\Requests\StoreCourseRequest;
use App\Http\Resources\CourseResource;
use App\Http\Resources\PublicCourseResource;
use App\Models\Course;
use App\Services\CourseCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CourseController extends Controller
{
    public function index(ListPublicCoursesRequest $request): JsonResponse
    {
        $input = $request->validated();

        return PublicCourseResource::collection(Course::publiclyVisible()
            ->with(['specialty:id,name', 'coach:id,name'])->orderByDesc('id')
            ->paginate($input['per_page'] ?? 20)->withQueryString())
            ->response()->header('Cache-Control', 'no-store');
    }

    public function store(StoreCourseRequest $request, CourseCreationService $courses): JsonResponse
    {
        $course = $courses->create($request->user(), $request->validated());

        return (new CourseResource($course))->response()
            ->setStatusCode(201)->header('Cache-Control', 'no-store, private');
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

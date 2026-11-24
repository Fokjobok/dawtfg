<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListEnrollmentsRequest;
use App\Http\Requests\StoreEnrollmentRequest;
use App\Http\Resources\EnrollmentResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class EnrollmentController extends Controller
{
    public function index(ListEnrollmentsRequest $request): JsonResponse
    {
        $input = $request->validated();
        $ids = $input['filter']['course_ids'] ?? null;

        return EnrollmentResource::collection($request->user('web')->enrollments()
            ->when($ids !== null, fn ($query) => $query->whereIn('course_id', $ids))
            ->with(['course' => fn ($query) => $query->publiclyVisible()->with(['specialty:id,name', 'coach:id,name,avatar_path,is_private,medals_is_private', 'coach.medals.specialty:id,name'])])
            ->orderByDesc('id')->paginate($input['per_page'] ?? 20)->withQueryString())
            ->response()->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, Enrollment $enrollment): JsonResponse
    {
        Gate::forUser($request->user('web'))->authorize('view', $enrollment);

        return $this->response($enrollment);
    }

    public function store(StoreEnrollmentRequest $request, Course $course, EnrollmentService $enrollments): JsonResponse
    {
        $result = $enrollments->enroll($request->user('web'), $course);
        $response = $this->response($result['enrollment'])->setStatusCode($result['created'] ? 201 : 200);
        if ($result['created']) {
            $response->header('Location', route('api.v1.enrollments.show', ['enrollment' => $result['enrollment']->id], false));
        }

        return $response;
    }

    public function destroy(Request $request, Enrollment $enrollment, EnrollmentService $enrollments): Response
    {
        $enrollments->withdraw($request->user('web'), $enrollment);

        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }

    private function response(Enrollment $enrollment): JsonResponse
    {
        $enrollment->load(['course' => fn ($query) => $query->publiclyVisible()->with(['specialty:id,name', 'coach:id,name,avatar_path,is_private,medals_is_private', 'coach.medals.specialty:id,name'])]);

        return (new EnrollmentResource($enrollment))->response()->header('Cache-Control', 'no-store, private');
    }
}

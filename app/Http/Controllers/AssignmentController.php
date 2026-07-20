<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAssignmentRequest;
use App\Http\Requests\UpdateAssignmentRequest;
use App\Models\Assignment;
use App\Services\AssignmentService;
use Illuminate\Http\JsonResponse;

class AssignmentController extends Controller
{
    public function __construct(private AssignmentService $assignmentService) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Assignment::class);

        return response()->json([
            'data' => $this->assignmentService->getVisibleAssignments(auth('api')->user()),
        ]);
    }

    public function store(StoreAssignmentRequest $request): JsonResponse
    {
        $assignment = $this->assignmentService->createAssignment($request->validated());

        return response()->json(['message' => 'Assignment created', 'data' => $assignment], 201);
    }

    public function update(UpdateAssignmentRequest $request, Assignment $assignment): JsonResponse
    {
        $updated = $this->assignmentService->updateAssignment($assignment, $request->validated());

        return response()->json(['message' => 'Assignment updated', 'data' => $updated]);
    }
}

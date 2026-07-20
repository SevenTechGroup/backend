<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReportRequest;
use App\Http\Requests\UpdateReportRequest;
use App\Models\Report;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(private ReportService $reportService) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Report::class);

        return response()->json([
            'data' => $this->reportService->getVisibleReports(auth('api')->user()),
        ]);
    }

    public function store(StoreReportRequest $request): JsonResponse
    {
        $report = $this->reportService->createReport($request->validated(), auth('api')->id());

        return response()->json(['message' => 'Report created', 'data' => $report], 201);
    }

    public function show(Report $report): JsonResponse
    {
        $this->authorize('view', $report);

        return response()->json(['data' => $this->reportService->getReport($report)]);
    }

    public function update(UpdateReportRequest $request, Report $report): JsonResponse
    {
        $updated = $this->reportService->updateReport($report, $request->validated());

        return response()->json(['message' => 'Report updated', 'data' => $updated]);
    }
}

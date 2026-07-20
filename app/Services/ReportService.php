<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Notification;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public function getVisibleReports(User $user): Collection
    {
        $query = Report::query();

        if ($user->hasRole(UserRole::Agent)) {
            $query->whereHas(
                'assignments',
                fn ($assignmentQuery) => $assignmentQuery->where('user_id', $user->id),
            );
        } elseif (! $user->hasRole(UserRole::Manager)) {
            $query->where('user_id', $user->id);
        }

        return $query
            ->with(['category', 'territory', 'user'])
            ->latest()
            ->get();
    }

    public function createReport(array $data, int $userId): Report
    {
        return DB::transaction(function () use ($data, $userId): Report {
            $report = Report::create([
                ...$data,
                'user_id' => $userId,
                'status' => 'received',
            ]);

            Notification::create([
                'user_id' => $userId,
                'message' => 'Nouveau signalement créé : '.$report->title,
            ]);

            return $report;
        });
    }

    public function getReport(Report $report): Report
    {
        return $report->load(['category', 'territory', 'user']);
    }

    public function updateReport(Report $report, array $data): Report
    {
        $report->update($data);

        return $report->fresh(['category', 'territory', 'user']);
    }

    public function getReportsByUser(int $userId): Collection
    {
        return Report::where('user_id', $userId)
            ->with(['category', 'territory'])
            ->latest()
            ->get();
    }
}

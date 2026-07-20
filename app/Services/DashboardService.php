<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Assignment;
use App\Models\Notification;
use App\Models\Report;
use App\Models\User;

class DashboardService
{
    public function getDashboardStats(User $user): array
    {
        $reportQuery = Report::query();
        $assignmentQuery = Assignment::query();

        if ($user->hasRole(UserRole::Agent)) {
            $reportQuery->whereHas(
                'assignments',
                fn ($query) => $query->where('user_id', $user->id),
            );
            $assignmentQuery->where('user_id', $user->id);
        } elseif (! $user->hasRole(UserRole::Manager)) {
            $reportQuery->where('user_id', $user->id);
            $assignmentQuery->whereRaw('1 = 0');
        }

        return [
            'total_reports' => $reportQuery->count(),
            'total_assignments' => $assignmentQuery->count(),
            'unread_notifications' => Notification::where('user_id', $user->id)
                ->where('is_read', false)
                ->count(),
            'my_reports' => Report::where('user_id', $user->id)->count(),
            'my_assignments' => Assignment::where('user_id', $user->id)->count(),
        ];
    }
}

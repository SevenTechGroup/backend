<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Assignment;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
    public function getVisibleAssignments(User $user): Collection
    {
        return Assignment::query()
            ->when(
                $user->hasRole(UserRole::Agent),
                fn ($query) => $query->where('user_id', $user->id),
            )
            ->with(['report.category', 'report.territory', 'user'])
            ->latest()
            ->get();
    }

    public function createAssignment(array $data): Assignment
    {
        return DB::transaction(function () use ($data): Assignment {
            $assignment = Assignment::create([...$data, 'status' => 'assigned']);

            Notification::create([
                'user_id' => $data['user_id'],
                'message' => 'Un nouveau signalement vous a été confié.',
            ]);

            return $assignment->load(['report.category', 'report.territory', 'user']);
        });
    }

    public function updateAssignment(Assignment $assignment, array $data): Assignment
    {
        $assignment->update($data);

        return $assignment->fresh(['report.category', 'report.territory', 'user']);
    }

    public function getAssignmentsByUser(int $userId): Collection
    {
        return Assignment::where('user_id', $userId)
            ->with(['report.category', 'report.territory'])
            ->latest()
            ->get();
    }
}

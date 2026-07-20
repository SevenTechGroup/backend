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
            ->with(['report', 'user'])
            ->latest()
            ->get();
    }

    public function createAssignment(array $data): Assignment
    {
        return DB::transaction(function () use ($data): Assignment {
            $assignment = Assignment::create([...$data, 'status' => 'assigned']);

            Notification::create([
                'user_id' => $data['user_id'],
                'message' => 'Un nouveau dossier vous a été assigné.',
            ]);

            return $assignment;
        });
    }

    public function updateAssignment(Assignment $assignment, array $data): Assignment
    {
        $assignment->update($data);

        return $assignment->fresh(['report', 'user']);
    }

    public function getAssignmentsByUser(int $userId): Collection
    {
        return Assignment::where('user_id', $userId)
            ->with('report')
            ->latest()
            ->get();
    }
}

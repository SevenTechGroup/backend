<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Assignment;
use App\Models\User;

class AssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Manager);
    }

    public function update(User $user, Assignment $assignment): bool
    {
        return $user->hasRole(UserRole::Manager)
            || ($user->hasRole(UserRole::Agent) && $assignment->user_id === $user->id);
    }
}

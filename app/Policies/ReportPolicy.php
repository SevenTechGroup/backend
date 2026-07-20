<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Report;
use App\Models\User;

class ReportPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Report $report): bool
    {
        if ($user->hasRole(UserRole::Manager)) {
            return true;
        }

        if ($user->hasRole(UserRole::Agent)) {
            return $report->assignments()->where('user_id', $user->id)->exists();
        }

        return $report->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Report $report): bool
    {
        return $user->hasRole(UserRole::Manager)
            || ($user->hasRole(UserRole::Agent)
                && $report->assignments()->where('user_id', $user->id)->exists());
    }
}

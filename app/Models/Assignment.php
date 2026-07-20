<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Assignment extends Model
{
    protected $fillable = ['report_id', 'user_id', 'notes', 'status'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canTransitionTo(AssignmentStatus $nextStatus): bool
    {
        if ($this->status === $nextStatus->value) {
            return true;
        }

        $transitions = [
            AssignmentStatus::Assigned->value => [AssignmentStatus::InProgress->value],
            AssignmentStatus::InProgress->value => [AssignmentStatus::Completed->value],
            AssignmentStatus::Completed->value => [],
        ];

        return in_array($nextStatus->value, $transitions[$this->status] ?? [], true);
    }
}

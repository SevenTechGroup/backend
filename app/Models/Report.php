<?php

namespace App\Models;

use App\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Report extends Model
{
    protected $fillable = ['title', 'description', 'category_id', 'territory_id', 'user_id', 'location_text', 'priority', 'status'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function location(): HasOne
    {
        return $this->hasOne(ReportLocation::class);
    }

    public function consentRecords(): HasMany
    {
        return $this->hasMany(ConsentRecord::class);
    }

    public function canTransitionTo(ReportStatus $nextStatus): bool
    {
        if ($this->status === $nextStatus->value) {
            return true;
        }

        $transitions = [
            ReportStatus::Received->value => [ReportStatus::InProgress->value],
            ReportStatus::InProgress->value => [ReportStatus::Resolved->value],
            ReportStatus::Resolved->value => [],
        ];

        return in_array($nextStatus->value, $transitions[$this->status] ?? [], true);
    }
}

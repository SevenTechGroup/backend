<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportLocation extends Model
{
    protected $fillable = [
        'report_id',
        'latitude',
        'longitude',
        'accuracy_m',
        'source',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_m' => 'float',
        ];
    }
}

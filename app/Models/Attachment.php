<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    protected $fillable = [
        'report_id',
        'provider',
        'provider_asset_id',
        'provider_public_id',
        'resource_type',
        'delivery_type',
        'format',
        'mime_type',
        'original_filename',
        'bytes',
        'width',
        'height',
        'secure_url',
    ];

    protected $hidden = [
        'provider_asset_id',
        'provider_public_id',
        'secure_url',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdempotencyKey extends Model
{
    /**
     * Durée de vie par défaut d'une clé d'idempotence (secondes).
     */
    public const TTL_SECONDS = 86400;

    protected $fillable = ['key', 'request_fingerprint', 'status', 'response_status', 'response_body', 'report_id'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /**
     * Indique si la clé a dépassé sa durée de vie configurée.
     */
    public function isExpired(): bool
    {
        if ($this->created_at === null) {
            return false;
        }

        $ttl = (int) config('idempotency.ttl', self::TTL_SECONDS);

        return $this->created_at->diffInSeconds(now()) > $ttl;
    }
}

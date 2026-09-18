<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The durable local record of one synchronous charge attempt against
 * Clover's /v1/charges API, written *before* Clover is ever called so a
 * lost or ambiguous response still has something to resolve against.
 * idempotency_key is sent as both Clover's idempotency key and its
 * external_reference_id; the resolver job searches by it but never uses it
 * to re-submit a charge.
 */
class CloverChargeAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id', 'idempotency_key', 'clover_charge_id', 'status', 'decline_reason',
        'attempts_count', 'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts_count' => 'integer',
            'last_checked_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The durable record of one MPGS attempt, written at prepare time before any
 * PAY call so a lost or ambiguous response still has something to resolve
 * against. order_id is the gateway order id and is never reused.
 */
class MpgsChargeAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id', 'sequence', 'order_id', 'auth_transaction_id', 'transaction_id', 'session_id',
        'status', 'auth_status', 'gateway_code', 'decline_reason', 'receipt',
        'attempts_count', 'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'session_id' => 'encrypted',
            'sequence' => 'integer',
            'attempts_count' => 'integer',
            'last_checked_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}

<?php

namespace App\Models;

use Database\Factories\PaymentAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One verified provider outcome for a payment (spec 0016). Diagnostic only: never changes a payment status.
 */
class PaymentAttempt extends Model
{
    /** @use HasFactory<PaymentAttemptFactory> */
    use HasFactory;

    protected $fillable = [
        'payment_id', 'provider', 'sequence', 'event_key', 'event', 'status',
        'amount', 'currency', 'amount_reported', 'reference', 'error_code', 'reason',
        'source', 'request', 'response', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'amount' => 'integer',
            'amount_reported' => 'boolean',
            'request' => 'array',
            'response' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}

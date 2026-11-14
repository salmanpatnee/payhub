<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One NMI charge call (or one 3DS failure report) and what the gateway answered. Written before
 * the call (status sent) so a timeout still leaves a row. Diagnostic only: it never decides the
 * payment's status (NmiChargeResolver does).
 */
class NmiChargeAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id', 'sequence', 'method', 'status', 'response_code', 'reason',
        'transaction_id', 'avs_result', 'cvv_result', 'three_ds_result',
    ];

    protected function casts(): array
    {
        return ['sequence' => 'integer'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}

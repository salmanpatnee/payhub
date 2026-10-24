<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Authorize.net charge call and what the gateway answered. Written before the
 * call (status sent) so a timeout still leaves a row. Diagnostic only: it never
 * decides the payment's status (AuthorizenetChargeResolver does).
 */
class AuthorizenetChargeAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id', 'sequence', 'status', 'response_code', 'error_code', 'reason',
        'transaction_id', 'avs_result', 'cvv_result', 'cavv_result',
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

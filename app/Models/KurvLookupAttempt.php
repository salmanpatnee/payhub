<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Kurv lookup and what Kurv answered (spec 0010, AC-11). Diagnostic only: it never
 * decides the payment's status (KurvChargeResolver does) and never holds the key or card data.
 */
class KurvLookupAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'payment_id', 'source', 'outcome', 'kurv_status', 'result_code', 'reason',
        'kurv_request_id', 'kurv_payment_id',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Worldpay lookup for one page and what Worldpay answered (spec 0014, AC-9). Diagnostic
 * only: it never decides the payment's status (WorldpayChargeResolver does) and never holds
 * a username, password, URL or signature.
 */
class WorldpayLookupAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'payment_id', 'page_id', 'source', 'outcome', 'last_event', 'reason',
        'worldpay_payment_id', 'checks', 'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'checks' => 'integer',
            'last_checked_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(WorldpayPage::class, 'page_id');
    }
}

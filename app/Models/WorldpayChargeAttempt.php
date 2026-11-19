<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Worldpay card charge, 3D Secure step or page event (spec 0015, AC-11). Diagnostic only,
 * except that WorldpayChargeResolver reads `transaction_reference` and `outcome` to know which
 * references to look up. It never decides the payment's status.
 */
class WorldpayChargeAttempt extends Model
{
    use HasFactory;

    public const OUTCOME_APPROVED = 'approved';

    public const OUTCOME_REFUSED = 'refused';

    public const OUTCOME_CHALLENGE_REQUIRED = 'challenge_required';

    public const OUTCOME_CHALLENGE_FAILED = 'challenge_failed';

    public const OUTCOME_UNCLEAR = 'unclear';

    public const OUTCOME_ERROR = 'error';

    public const OUTCOME_ABANDONED = 'abandoned';

    public const OUTCOME_NOT_FOUND = 'not_found';

    /** Still being decided: the resolver looks these up on every run. */
    public const NON_FINAL = [self::OUTCOME_APPROVED, self::OUTCOME_UNCLEAR, self::OUTCOME_CHALLENGE_REQUIRED];

    /** Looked up once from the sweep, near the end of its window. */
    public const FINAL = [self::OUTCOME_REFUSED, self::OUTCOME_CHALLENGE_FAILED, self::OUTCOME_ABANDONED, self::OUTCOME_NOT_FOUND];

    public const STEP_EVENT = 'event';

    protected $fillable = [
        'payment_id', 'sequence', 'transaction_reference', 'method', 'outcome', 'step', 'last_event',
        'response_code', 'reason', 'worldpay_payment_id', 'client_info',
        // three_ds_state is NOT mass assignable, assign explicitly only
    ];

    protected $hidden = ['three_ds_state'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'three_ds_state' => 'encrypted',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function lookups(): HasMany
    {
        return $this->hasMany(WorldpayLookupAttempt::class, 'charge_attempt_id');
    }

    /** Page reports carry no sequence or reference and never count as a charge. */
    public function scopeReal($query)
    {
        return $query->where('step', '!=', self::STEP_EVENT);
    }
}

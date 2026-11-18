<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Worldpay hosted page created for a payment (spec 0014). The url is only the redirect
 * target and is never put in Inertia props.
 */
class WorldpayPage extends Model
{
    protected $fillable = [
        'payment_id', 'sequence', 'transaction_reference', 'url', 'expires_at',
        'last_event', 'closed_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(WorldpayLookupAttempt::class, 'page_id');
    }

    /**
     * Dead by the spec 0014 AC-7 rule: closed, refused or cancelled (unless the client may retry on the
     * same page), or older than its expiry plus the grace time.
     */
    public function isDead(): bool
    {
        if ($this->closed_at !== null) {
            return true;
        }

        if (! config('services.worldpay.reuse_after_decline')
            && in_array($this->last_event, (array) config('services.worldpay.declined_events', []), true)) {
            return true;
        }

        return $this->expires_at->copy()->addSeconds((int) config('services.worldpay.page_grace_seconds', 120))->isPast();
    }

    /** A page whose create call worked: it has a url and did not fail. */
    public function scopeUsable($query)
    {
        return $query->whereNull('failed_at')->whereNotNull('url');
    }
}

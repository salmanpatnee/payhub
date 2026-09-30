<?php

namespace App\Models;

use App\Enums\StripeHealthStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StripeAccountHealth extends Model
{
    use HasFactory;

    protected $table = 'stripe_account_health';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => StripeHealthStatus::class,
            'charges_enabled' => 'boolean',
            'payouts_enabled' => 'boolean',
            'details_submitted' => 'boolean',
            'requirements' => 'array',
            'status_changed_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function stripeAccount(): BelongsTo
    {
        return $this->belongsTo(StripeAccount::class);
    }
}

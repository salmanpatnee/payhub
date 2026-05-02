<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'brand_id', 'stripe_account_id', 'user_id',
        'amount', 'currency', 'description', 'status',
        'client_email', 'stripe_payment_intent_id', 'expires_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'     => 'integer',
            'expires_at' => 'datetime',
            'paid_at'    => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function stripeAccount(): BelongsTo
    {
        return $this->belongsTo(StripeAccount::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

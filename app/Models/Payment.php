<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'brand_id', 'stripe_account_id', 'user_id',
        'amount', 'currency', 'status',
        'client_email', 'client_name',
        'service', 'package', 'note',
        'stripe_payment_intent_id', 'expires_at', 'paid_at',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Payment $payment): void {
            if (empty($payment->uuid)) {
                $payment->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'uuid'       => 'string',
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

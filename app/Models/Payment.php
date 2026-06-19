<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'reference_code', 'provider', 'brand_id', 'stripe_account_id', 'revolut_account_id', 'user_id', 'relationship_manager_id',
        'amount', 'currency', 'status',
        'client_email', 'client_name',
        'service', 'package', 'note',
        'stripe_payment_intent_id', 'revolut_order_id', 'expires_at', 'paid_at',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Payment $payment): void {
            if (empty($payment->uuid)) {
                $payment->uuid = (string) Str::uuid();
            }
            if (empty($payment->reference_code)) {
                // Lock held until the enclosing transaction commits (which includes
                // the INSERT) so concurrent creates can't compute the same code.
                // The create call must run inside a DB transaction — see
                // PaymentController::createPaymentWithRetry().
                //
                // withTrashed(): the reference_code unique index covers soft-deleted
                // rows too, so the max MUST include trashed rows — otherwise a deleted
                // payment's code gets regenerated and collides with the dead row.
                $payment->reference_code = max(Payment::withTrashed()->lockForUpdate()->max('reference_code') ?? 0, 1000) + 1;
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
            'uuid' => 'string',
            'reference_code' => 'integer',
            'provider' => PaymentProvider::class,
            'amount' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
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

    public function revolutAccount(): BelongsTo
    {
        return $this->belongsTo(RevolutAccount::class);
    }

    /**
     * Display name of the payment account that processed (or will process) this
     * payment, resolved by provider. Null if the account is missing.
     */
    public function formattedReferenceCode(): string
    {
        $prefix = $this->provider === PaymentProvider::Revolut
            ? $this->revolutAccount?->prefix
            : $this->stripeAccount?->prefix;
        $number = str_pad((string) ($this->reference_code ?? 0), 6, '0', STR_PAD_LEFT);

        return $prefix ? "{$prefix}-{$number}" : "#{$number}";
    }

    public function providerAccountName(): ?string
    {
        return $this->provider === PaymentProvider::Revolut
            ? $this->revolutAccount?->account_name
            : $this->stripeAccount?->account_name;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function relationshipManager(): BelongsTo
    {
        return $this->belongsTo(RelationshipManager::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(PaymentConsent::class);
    }
}

<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use Illuminate\Database\Eloquent\Builder;
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
        'uuid', 'reference_code', 'provider', 'brand_id',
        'stripe_account_id', 'revolut_account_id', 'square_account_id', 'viva_account_id', 'clover_account_id', 'mpgs_account_id', 'authorizenet_account_id',
        'user_id', 'relationship_manager_id',
        'amount', 'currency', 'status',
        'client_email', 'client_name',
        'service', 'package', 'note',
        'stripe_payment_intent_id', 'revolut_order_id', 'square_payment_id',
        'viva_transaction_id', 'viva_order_code', 'expires_at', 'paid_at',
        'clover_payment_id', 'mpgs_order_id', 'authorizenet_transaction_id',
        'authorizenet_first_attempt_at', 'authorizenet_last_attempt_at',
        'card_locked_until', 'card_attempts_since',
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
            'authorizenet_first_attempt_at' => 'datetime',
            'authorizenet_last_attempt_at' => 'datetime',
            'card_locked_until' => 'datetime',
            'card_attempts_since' => 'datetime',
        ];
    }

    public function isCardLocked(): bool
    {
        return $this->card_locked_until?->isFuture() ?? false;
    }

    /**
     * @param  Builder<Payment>  $query
     */
    public function scopeCardLocked(Builder $query): void
    {
        $query->where('card_locked_until', '>', now());
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

    public function squareAccount(): BelongsTo
    {
        return $this->belongsTo(SquareAccount::class);
    }

    public function vivaAccount(): BelongsTo
    {
        return $this->belongsTo(VivaAccount::class);
    }

    public function cloverAccount(): BelongsTo
    {
        return $this->belongsTo(CloverAccount::class);
    }

    public function mpgsAccount(): BelongsTo
    {
        return $this->belongsTo(MpgsAccount::class);
    }

    public function authorizenetAccount(): BelongsTo
    {
        return $this->belongsTo(AuthorizenetAccount::class);
    }

    public function mpgsChargeAttempts(): HasMany
    {
        return $this->hasMany(MpgsChargeAttempt::class);
    }

    public function authorizenetChargeAttempts(): HasMany
    {
        return $this->hasMany(AuthorizenetChargeAttempt::class);
    }

    public function cloverChargeAttempts(): HasMany
    {
        return $this->hasMany(CloverChargeAttempt::class);
    }

    /**
     * Reference code prefixed with the processing account's prefix (resolved by
     * provider), e.g. "ACME-001234". Falls back to "#001234" when no prefix.
     *
     * Match arms are exhaustive by design (no default) so an unhandled provider
     * throws UnhandledMatchError instead of silently resolving to Stripe's
     * account — see git history for the Square CSV export bugs this pattern
     * previously caused.
     */
    public function formattedReferenceCode(): string
    {
        $prefix = match ($this->provider) {
            PaymentProvider::Stripe => $this->stripeAccount?->prefix,
            PaymentProvider::Revolut => $this->revolutAccount?->prefix,
            PaymentProvider::Square => $this->squareAccount?->prefix,
            PaymentProvider::Viva => $this->vivaAccount?->prefix,
            PaymentProvider::Clover => $this->cloverAccount?->prefix,
            PaymentProvider::Mpgs => $this->mpgsAccount?->prefix,
            PaymentProvider::Authorizenet => $this->authorizenetAccount?->prefix,
        };
        $number = str_pad((string) ($this->reference_code ?? 0), 6, '0', STR_PAD_LEFT);

        return $prefix ? "{$prefix}-{$number}" : "#{$number}";
    }

    /**
     * URL-safe reference code for the shareable pay link (/pay/{uuid}/{ref}):
     * "PREFIX-000000", or just "000000" when the account has no prefix (no '#').
     * Display only; never used for lookup.
     */
    public function urlReference(): ?string
    {
        if ($this->reference_code === null) {
            return null;
        }

        return preg_replace('/[^A-Za-z0-9_-]/', '', ltrim($this->formattedReferenceCode(), '#'));
    }

    /**
     * Display name of the payment account that processed (or will process) this
     * payment, resolved by provider. Null if the account is missing.
     *
     * Match arms are exhaustive by design — see formattedReferenceCode() note.
     */
    public function providerAccountName(): ?string
    {
        return match ($this->provider) {
            PaymentProvider::Stripe => $this->stripeAccount?->account_name,
            PaymentProvider::Revolut => $this->revolutAccount?->account_name,
            PaymentProvider::Square => $this->squareAccount?->account_name,
            PaymentProvider::Viva => $this->vivaAccount?->account_name,
            PaymentProvider::Clover => $this->cloverAccount?->account_name,
            PaymentProvider::Mpgs => $this->mpgsAccount?->account_name,
            PaymentProvider::Authorizenet => $this->authorizenetAccount?->account_name,
        };
    }

    /**
     * Unified account name accessor for cross-provider reporting.
     */
    public function getAccountNameAttribute(): ?string
    {
        return $this->providerAccountName();
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

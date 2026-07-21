<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Enums\SupportedCurrency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPaymentAccount extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'currency', 'provider', 'account_id'];

    protected function casts(): array
    {
        return [
            'currency' => SupportedCurrency::class,
            'provider' => PaymentProvider::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

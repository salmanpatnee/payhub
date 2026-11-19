<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorldpayAccount extends Model
{
    use HasFactory;

    public const ENVIRONMENTS = ['try', 'live'];

    public const MODES = ['hosted', 'card_fields'];

    protected $fillable = [
        'account_name', 'prefix', 'entity_ref', 'environment', 'webhook_key_id', 'is_active',
        'mode', 'checkout_id', 'google_pay_enabled', 'google_pay_gateway_merchant_id',
        // api_username, api_password and webhook_secret are NOT mass-assignable, assign explicitly only
    ];

    protected function casts(): array
    {
        return [
            'api_username' => 'encrypted',
            'api_password' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'is_active' => 'boolean',
            'google_pay_enabled' => 'boolean',
        ];
    }

    public function isCardFields(): bool
    {
        return $this->mode === 'card_fields';
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}

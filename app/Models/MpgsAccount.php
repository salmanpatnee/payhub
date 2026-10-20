<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MpgsAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_name', 'prefix', 'merchant_id', 'api_version', 'gateway_host', 'supports_pkr', 'is_active',
        // integration_password and webhook_secret are NOT mass-assignable — assign explicitly only
    ];

    protected function casts(): array
    {
        return [
            'integration_password' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'api_version' => 'integer',
            'supports_pkr' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The gateway host this account talks to: its own gateway_host when set
     * (the bank's test and live hosts differ), else the configured default.
     * No trailing slash.
     */
    public function gatewayHost(): string
    {
        return rtrim($this->gateway_host ?: (string) config('services.mpgs.host'), '/');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}

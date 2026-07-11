<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VivaAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_name', 'prefix', 'client_id', 'merchant_id', 'source_code', 'environment', 'is_active',
        // client_secret, api_key, and webhook_verification_key are NOT mass-assignable — assign explicitly only
    ];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'api_key' => 'encrypted',
            'webhook_verification_key' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}

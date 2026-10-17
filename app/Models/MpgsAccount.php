<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MpgsAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_name', 'prefix', 'merchant_id', 'api_version', 'is_active',
        // integration_password and webhook_secret are NOT mass-assignable — assign explicitly only
    ];

    protected function casts(): array
    {
        return [
            'integration_password' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'api_version' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}

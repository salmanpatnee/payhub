<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CloverAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_name', 'prefix', 'merchant_id', 'api_access_key', 'currency', 'environment', 'is_active',
        // private_token is NOT mass-assignable — assign explicitly only
    ];

    protected function casts(): array
    {
        return [
            'private_token' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}

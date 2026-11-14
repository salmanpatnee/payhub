<?php

namespace App\Models;

use App\Enums\NmiEnvironment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NmiAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_name', 'prefix', 'environment', 'currency', 'public_tokenization_key',
        'three_ds_enabled', 'google_pay_enabled', 'is_active',
        // private_key and webhook_signing_key are NOT mass-assignable: assign explicitly only
    ];

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'webhook_signing_key' => 'encrypted',
            'environment' => NmiEnvironment::class,
            'three_ds_enabled' => 'boolean',
            'google_pay_enabled' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}

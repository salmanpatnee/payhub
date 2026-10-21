<?php

namespace App\Models;

use App\Enums\AuthorizenetEnvironment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuthorizenetAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_name', 'prefix', 'api_login_id', 'environment', 'currency', 'is_active',
        // transaction_key and signature_key are NOT mass-assignable — assign explicitly only
    ];

    protected function casts(): array
    {
        return [
            'transaction_key' => 'encrypted',
            'signature_key' => 'encrypted',
            'environment' => AuthorizenetEnvironment::class,
            'is_active' => 'boolean',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}

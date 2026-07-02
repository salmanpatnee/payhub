<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SquareAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_name', 'prefix', 'application_id', 'location_id', 'environment', 'is_active',
        // access_token and webhook_signature_key are NOT mass-assignable — assign explicitly only
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'webhook_signature_key' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}

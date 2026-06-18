<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RevolutAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_name', 'public_key', 'is_active', 'prefix',
        // secret_key and webhook_secret are NOT mass-assignable — assign explicitly only
    ];

    protected function casts(): array
    {
        return [
            'secret_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}

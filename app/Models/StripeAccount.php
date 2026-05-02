<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StripeAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id', 'account_name', 'publishable_key',
        'secret_key', 'webhook_secret', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'secret_key'     => 'encrypted',
            'webhook_secret' => 'encrypted',
            'is_active'      => 'boolean',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KurvAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_name', 'prefix', 'environment', 'is_active',
        // api_key is NOT mass-assignable, assign explicitly only
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    /**
     * A kp_test_ key belongs with sandbox and a kp_live_ key with production (spec 0010, AC-1).
     * A key with any other prefix is refused.
     */
    public static function keyMatchesEnvironment(string $key, string $environment): bool
    {
        return match ($environment) {
            'sandbox' => str_starts_with($key, 'kp_test_'),
            'production' => str_starts_with($key, 'kp_live_'),
            default => false,
        };
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}

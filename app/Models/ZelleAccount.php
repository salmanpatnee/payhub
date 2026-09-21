<?php

namespace App\Models;

use App\Enums\SupportedCurrency;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ZelleAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $attributes = [
        'currency' => 'usd',
    ];

    protected $fillable = [
        'account_name', 'email', 'mobile_number', 'currency', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'currency' => SupportedCurrency::class,
            'is_active' => 'boolean',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => mb_strtolower(trim($value)),
        );
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_zelle_account');
    }
}

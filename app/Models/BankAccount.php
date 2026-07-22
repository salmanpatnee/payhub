<?php

namespace App\Models;

use App\Enums\SupportedCurrency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'bank_name', 'account_name', 'account_number', 'currency',
        'sort_code', 'routing_number', 'iban', 'swift_bic',
        'bank_address', 'bank_country', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'currency' => SupportedCurrency::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}

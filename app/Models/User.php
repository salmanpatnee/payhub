<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'username', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<UserPaymentAccount, $this>
     */
    public function paymentAccounts(): HasMany
    {
        return $this->hasMany(UserPaymentAccount::class);
    }

    /**
     * Brands this user (agent) is mapped to.
     *
     * @return BelongsToMany<Brand, $this>
     */
    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class);
    }

    /**
     * Relationship managers this user (agent) is mapped to.
     *
     * @return BelongsToMany<RelationshipManager, $this>
     */
    public function relationshipManagers(): BelongsToMany
    {
        return $this->belongsToMany(RelationshipManager::class);
    }

    /**
     * Company bank accounts assigned to this user for client-facing sharing.
     *
     * @return BelongsToMany<BankAccount, $this>
     */
    public function bankAccounts(): BelongsToMany
    {
        return $this->belongsToMany(BankAccount::class);
    }
}

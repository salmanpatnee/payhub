<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RelationshipManager extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Agents mapped to this relationship manager.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}

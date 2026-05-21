<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedStripeEvent extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'stripe_event_id';

    protected $keyType = 'string';

    protected $fillable = ['stripe_event_id', 'processed_at'];

    protected $casts = ['processed_at' => 'datetime'];
}

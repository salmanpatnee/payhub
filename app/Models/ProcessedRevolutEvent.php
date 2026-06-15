<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedRevolutEvent extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'event_key';

    protected $keyType = 'string';

    protected $fillable = ['event_key', 'processed_at'];

    protected $casts = ['processed_at' => 'datetime'];
}

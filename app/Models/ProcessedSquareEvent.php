<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedSquareEvent extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'square_event_id';

    protected $keyType = 'string';

    protected $fillable = ['square_event_id', 'processed_at'];

    protected $casts = ['processed_at' => 'datetime'];
}

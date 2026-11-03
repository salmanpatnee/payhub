<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedWhopEvent extends Model
{
    protected $fillable = ['webhook_id', 'event_type'];
}

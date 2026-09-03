<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    protected $fillable = [
        'name',
        'phone_number',
        'twilio_worker_sid',
        'status',
    ];
}

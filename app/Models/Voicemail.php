<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Voicemail extends Model
{
    protected $fillable = [
        'call_id',
        'recording_sid',
        'recording_url',
        'sms_sid',
    ];

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Call extends Model
{
    protected $fillable = [
        'call_sid',
        'from_number',
        'status',
        'agent_id',
        'task_sid',
        'outcome',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function taskRecord(): HasOne
    {
        return $this->hasOne(TaskRecord::class);
    }

    public function voicemail(): HasOne
    {
        return $this->hasOne(Voicemail::class);
    }
}

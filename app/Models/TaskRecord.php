<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskRecord extends Model
{
    protected $fillable = [
        'task_sid',
        'call_id',
        'workflow_sid',
        'status',
        'reservation_sid',
    ];

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}

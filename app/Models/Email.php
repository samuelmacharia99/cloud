<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Email extends Model
{
    public $timestamps = false;
    public $updatedAt = false;

    protected $fillable = ['recipient', 'subject', 'body', 'status', 'response', 'sent_by', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }

    public function scopeBounced($query)
    {
        return $query->where('status', 'bounced');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Email extends Model
{
    public $timestamps = false;

    public $updatedAt = false;

    protected $fillable = ['recipient', 'user_id', 'subject', 'event_key', 'message_id', 'body', 'html_body', 'status', 'response', 'sent_by', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    /**
     * Platform admin inbox: hide reseller-sent mail and mail to reseller-managed customers.
     */
    public function scopeForAdminLog($query)
    {
        return $query
            ->where(function ($q) {
                $q->whereNull('sent_by')
                    ->orWhereDoesntHave('sentBy', fn ($user) => $user->where('is_reseller', true));
            })
            ->where(function ($q) {
                $q->whereNull('user_id')
                    ->orWhereDoesntHave('user', fn ($user) => $user->whereNotNull('reseller_id'));
            });
    }

    public function isVisibleOnAdminLog(): bool
    {
        if ($this->sentBy?->is_reseller) {
            return false;
        }

        if ($this->user?->reseller_id) {
            return false;
        }

        return true;
    }
}

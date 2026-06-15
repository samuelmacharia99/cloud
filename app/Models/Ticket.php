<?php

namespace App\Models;

use App\Enums\TicketHandledBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'reseller_id',
        'handled_by',
        'title',
        'description',
        'status',
        'priority',
        'assigned_to',
        'resolved_at',
        'escalated_at',
        'escalated_by',
        'escalation_note',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'escalated_at' => 'datetime',
        'handled_by' => TicketHandledBy::class,
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reseller()
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function escalatedByUser()
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies()
    {
        return $this->hasMany(TicketReply::class);
    }

    public function attachments()
    {
        return $this->hasMany(TicketAttachment::class)->whereNull('ticket_reply_id');
    }

    public function allAttachments()
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function isHandledByPlatform(): bool
    {
        return $this->handled_by === TicketHandledBy::Platform;
    }

    public function isHandledByReseller(): bool
    {
        return $this->handled_by === TicketHandledBy::Reseller;
    }

    public function isEscalated(): bool
    {
        return $this->escalated_at !== null;
    }

    /**
     * Tickets the platform admin may access (strict: reseller-customer tickets only after escalation).
     */
    public function scopeVisibleToAdmin(Builder $query): Builder
    {
        return $query->where('handled_by', TicketHandledBy::Platform->value);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', 'closed');
    }
}

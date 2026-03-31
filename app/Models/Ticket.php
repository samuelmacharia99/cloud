<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model {
    use HasFactory;
    protected $fillable = ["user_id", "tenant_id", "ticket_number", "subject", "description", "priority", "status"];
    
    public function user() { return $this->belongsTo(User::class); }
    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function replies() { return $this->hasMany(TicketReply::class); }
}

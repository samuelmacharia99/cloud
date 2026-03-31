<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable {
    use HasFactory, Notifiable;

    protected $fillable = [
        "tenant_id", "name", "email", "password", "phone", "role", "status",
        "two_factor_secret", "two_factor_confirmed_at", "last_login_at"
    ];

    protected $hidden = ["password", "remember_token", "two_factor_secret"];

    protected $casts = [
        "email_verified_at" => "datetime",
        "two_factor_confirmed_at" => "datetime",
        "last_login_at" => "datetime",
    ];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function services() { return $this->hasMany(Service::class); }
    public function domains() { return $this->hasMany(Domain::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }
    public function payments() { return $this->hasMany(Payment::class); }
    public function tickets() { return $this->hasMany(Ticket::class); }
    public function ticketReplies() { return $this->hasMany(TicketReply::class); }
    
    public function isSuperAdmin() { return $this->role === "super_admin"; }
    public function isReseller() { return $this->role === "reseller"; }
    public function isUser() { return $this->role === "user"; }
}

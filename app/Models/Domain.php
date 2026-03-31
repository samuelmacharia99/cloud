<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Domain extends Model {
    use HasFactory;
    protected $fillable = ["user_id", "tenant_id", "domain_name", "registrar", "registrar_id", "expires_at", "registered_at", "auto_renew", "status"];
    protected $casts = ["expires_at" => "date", "registered_at" => "date"];
    
    public function user() { return $this->belongsTo(User::class); }
    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function dnsZone() { return $this->hasOne(DnsZone::class); }
}

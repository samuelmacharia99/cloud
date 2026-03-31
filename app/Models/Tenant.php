<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model {
    use HasFactory;
    protected $fillable = ["name", "contact_email", "phone", "type", "settings", "status"];
    protected $casts = ["settings" => "json"];
    
    public function users() { return $this->hasMany(User::class); }
    public function products() { return $this->hasMany(Product::class); }
    public function domains() { return $this->hasMany(Domain::class); }
    public function services() { return $this->hasMany(Service::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }
    public function tickets() { return $this->hasMany(Ticket::class); }
}

<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model {
    use HasFactory;
    protected $fillable = ["user_id", "product_id", "tenant_id", "service_type", "name", "specs", "status", "grace_until"];
    protected $casts = ["specs" => "json", "grace_until" => "datetime"];
    
    public function user() { return $this->belongsTo(User::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function tenant() { return $this->belongsTo(Tenant::class); }
}

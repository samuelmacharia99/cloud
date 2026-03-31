<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model {
    use HasFactory;
    protected $fillable = ["tenant_id", "name", "description", "type", "pricing_monthly", "pricing_annual", "specs"];
    protected $casts = ["specs" => "json"];
    
    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function services() { return $this->hasMany(Service::class); }
}

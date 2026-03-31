<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DnsZone extends Model {
    use HasFactory;
    protected $fillable = ["domain_id", "zone_name", "nameservers"];
    protected $casts = ["nameservers" => "json"];
    
    public function domain() { return $this->belongsTo(Domain::class); }
    public function records() { return $this->hasMany(DnsRecord::class); }
}

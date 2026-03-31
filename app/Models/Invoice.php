<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model {
    use HasFactory;
    protected $fillable = ["user_id", "tenant_id", "invoice_number", "amount", "tax", "total", "status", "description", "due_date", "paid_at"];
    protected $casts = ["due_date" => "date", "paid_at" => "datetime"];
    
    public function user() { return $this->belongsTo(User::class); }
    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function payments() { return $this->hasMany(Payment::class); }
}

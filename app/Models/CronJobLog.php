<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CronJobLog extends Model {
    use HasFactory;
    protected $fillable = ["job_name", "status", "executed_at", "duration_ms", "error_message"];
    protected $casts = ["executed_at" => "datetime"];
}

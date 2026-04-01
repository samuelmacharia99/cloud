<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DnsZone extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain_id',
        'name',
        'status',
    ];

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }

    public function records()
    {
        return $this->hasMany(DnsRecord::class);
    }
}

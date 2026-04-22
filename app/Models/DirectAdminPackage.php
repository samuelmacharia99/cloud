<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DirectAdminPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'package_key',
        'disk_quota',
        'bandwidth_quota',
        'num_domains',
        'num_ftp',
        'num_email_accounts',
        'num_databases',
        'num_subdomains',
        'features',
        'is_active',
        'node_id',
    ];

    protected $casts = [
        'disk_quota' => 'decimal:2',
        'bandwidth_quota' => 'decimal:2',
        'num_domains' => 'integer',
        'num_ftp' => 'integer',
        'num_email_accounts' => 'integer',
        'num_databases' => 'integer',
        'num_subdomains' => 'integer',
        'features' => 'array',
        'is_active' => 'boolean',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function node()
    {
        return $this->belongsTo(Node::class);
    }

    public function getDisplayNameAttribute(): string
    {
        $name = "{$this->name} ({$this->disk_quota}GB)";
        if ($this->node_id && $this->node) {
            $name .= " - {$this->node->name}";
        }
        return $name;
    }
}

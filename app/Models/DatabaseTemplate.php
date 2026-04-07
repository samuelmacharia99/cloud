<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DatabaseTemplate extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'type',
        'versions',
        'docker_image',
        'default_port',
        'required_ram_mb',
        'hosting_type',
        'is_active',
        'order',
    ];

    protected $casts = [
        'versions' => 'array',
        'is_active' => 'boolean',
        'required_ram_mb' => 'integer',
        'default_port' => 'integer',
        'order' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('order');
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeForHostingType($query, $hostingType)
    {
        return $query->where('hosting_type', $hostingType);
    }
}

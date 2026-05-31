<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContainerTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'description',
        'category',
        'docker_image',
        'default_port',
        'required_ram_mb',
        'required_cpu_cores',
        'required_storage_gb',
        'environment_variables',
        'volume_paths',
        'compose_services',
        'setup_commands',
        'versions',
        'strict_health_check',
        'health_check_timeout_seconds',
        'is_active',
        'order',
    ];

    protected $casts = [
        'environment_variables' => 'array',
        'volume_paths' => 'array',
        'compose_services' => 'array',
        'setup_commands' => 'array',
        'versions' => 'array',
        'strict_health_check' => 'boolean',
        'health_check_timeout_seconds' => 'integer',
        'is_active' => 'boolean',
        'required_ram_mb' => 'integer',
        'required_cpu_cores' => 'decimal:1',
        'required_storage_gb' => 'integer',
        'default_port' => 'integer',
    ];

    // Relationships
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'container_template_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('order');
    }

    // Accessors & Helpers
    public function getRequiredEnvVars(): array
    {
        if (! $this->environment_variables) {
            return [];
        }

        return array_filter(
            $this->environment_variables,
            fn ($var) => $var['required'] ?? false
        );
    }

    public function getSecretEnvVars(): array
    {
        if (! $this->environment_variables) {
            return [];
        }

        return array_filter(
            $this->environment_variables,
            fn ($var) => $var['secret'] ?? false
        );
    }

    public function getOptionalEnvVars(): array
    {
        if (! $this->environment_variables) {
            return [];
        }

        return array_filter(
            $this->environment_variables,
            fn ($var) => ! ($var['required'] ?? false)
        );
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContainerTemplate extends Model
{
    use HasFactory;

    /**
     * Official Node images offered by the deployment version picker.
     *
     * @return list<string>
     */
    public static function nodeRuntimeVersions(): array
    {
        return [
            '18-alpine',
            '20-alpine',
            '22-alpine',
            '18-slim',
            '20-slim',
            '22-slim',
            '18',
            '20',
            '22',
        ];
    }

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

    /**
     * Customer deploy / tech-stack picker. Existing Ollama services keep running.
     */
    public function scopeOfferedForNewDeploy($query)
    {
        return $query->active()->where('slug', '!=', 'ollama');
    }

    public function isOfferedForNewDeploy(): bool
    {
        return $this->is_active && $this->slug !== 'ollama';
    }

    /**
     * Catalog row the PHP runtime image and Doctor “Switch to PHP” treatment need.
     * Production hosts that were seeded before this stack existed have no `php` row.
     *
     * @return array<string, mixed>
     */
    public static function phpRuntimeAttributes(): array
    {
        return [
            'name' => 'PHP Application',
            'description' => 'Generic PHP runtime for modern apps and APIs.',
            'category' => 'web',
            'docker_image' => 'talksasa/php-runtime:8.3',
            'default_port' => 8080,
            'required_ram_mb' => 256,
            'required_cpu_cores' => 0.5,
            'required_storage_gb' => 2,
            'versions' => [
                '8.1-cli',
                '8.2-cli',
                '8.3-cli',
                '8.4-cli',
            ],
            'environment_variables' => [
                [
                    'key' => 'APP_ENV',
                    'label' => 'Application Environment',
                    'default' => 'production',
                    'required' => false,
                    'secret' => false,
                ],
                [
                    'key' => 'APP_PORT',
                    'label' => 'Application Port',
                    'default' => '8080',
                    'required' => false,
                    'secret' => false,
                ],
            ],
            'volume_paths' => [
                'app_data' => '/app',
            ],
            'compose_services' => [],
            'setup_commands' => [],
            'strict_health_check' => true,
            'health_check_timeout_seconds' => 120,
            'is_active' => true,
            'order' => 6,
        ];
    }

    public static function ensurePhpRuntime(): self
    {
        $defaults = self::phpRuntimeAttributes();
        $template = static::query()
            ->where('slug', 'php')
            ->orderByRaw('is_active DESC')
            ->first();

        if (! $template) {
            return static::query()->create(array_merge(['slug' => 'php'], $defaults));
        }

        $dirty = false;
        if (! $template->is_active) {
            $template->is_active = true;
            $dirty = true;
        }

        $paths = is_array($template->volume_paths) ? $template->volume_paths : [];
        if (! array_key_exists('app_data', $paths)) {
            $template->volume_paths = array_merge($paths, $defaults['volume_paths']);
            $dirty = true;
        }

        if ((int) $template->default_port <= 0) {
            $template->default_port = $defaults['default_port'];
            $dirty = true;
        }

        if ($dirty) {
            $template->save();
        }

        return $template;
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

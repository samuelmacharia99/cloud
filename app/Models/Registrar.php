<?php

namespace App\Models;

use App\Enums\RegistrarDriver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Registrar extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'driver',
        'environment',
        'is_active',
        'is_default',
        'description',
        'config',
        'last_tested_at',
        'last_test_message',
        'sort_order',
    ];

    protected $casts = [
        'driver' => RegistrarDriver::class,
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'config' => 'encrypted:array',
        'last_tested_at' => 'datetime',
    ];

    public function domainExtensions(): HasMany
    {
        return $this->hasMany(DomainExtension::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Config safe for admin UI (secrets masked).
     *
     * @return array<string, mixed>
     */
    public function maskedConfig(): array
    {
        $config = $this->config ?? [];
        $masked = [];

        foreach ($config as $key => $value) {
            if ($this->isSecretKey((string) $key) && filled($value)) {
                $masked[$key] = '••••••••';
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }

    public function isSecretKey(string $key): bool
    {
        return str_contains($key, 'secret')
            || str_contains($key, 'password')
            || $key === 'api_key';
    }

    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'driver' => $this->driver->value,
            'driver_label' => $this->driver->label(),
            'environment' => $this->environment,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'description' => $this->description,
            'config' => $this->maskedConfig(),
            'tld_ids' => $this->domainExtensions->pluck('id')->all(),
            'tld_count' => $this->domainExtensions->count(),
            'last_tested_at' => $this->last_tested_at?->toIso8601String(),
            'last_test_message' => $this->last_test_message,
            'sort_order' => $this->sort_order,
        ];
    }
}

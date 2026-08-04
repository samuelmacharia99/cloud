<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerProject extends Model
{
    use HasFactory;

    public const DEFAULT_NAME = 'Project';

    protected $fillable = [
        'user_id',
        'name',
        'billing_service_id',
        'recipe_key',
        'resource_pool',
    ];

    protected function casts(): array
    {
        return [
            'resource_pool' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'project_id');
    }

    public function billingService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'billing_service_id');
    }
}

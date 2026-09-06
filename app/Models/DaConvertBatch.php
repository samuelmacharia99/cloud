<?php

namespace App\Models;

use App\Enums\DaConvertBatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DaConvertBatch extends Model
{
    protected $fillable = [
        'reseller_user_id',
        'admin_user_id',
        'product_id',
        'email_product_id',
        'acknowledge_mail_pull',
        'acknowledge_addon_sites',
        'status',
        'error',
    ];

    protected $casts = [
        'acknowledge_mail_pull' => 'boolean',
        'acknowledge_addon_sites' => 'boolean',
        'status' => DaConvertBatchStatus::class,
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_user_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function emailProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'email_product_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DaConvertBatchItem::class);
    }
}

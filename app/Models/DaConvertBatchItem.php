<?php

namespace App\Models;

use App\Enums\DaConvertBatchItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DaConvertBatchItem extends Model
{
    protected $fillable = [
        'da_convert_batch_id',
        'service_id',
        'reseller_product_id',
        'product_id',
        'status',
        'detected_stack',
        'mailbox_count',
        'has_addon_sites',
        'blockers',
        'error',
        'hostname',
        'target_ip',
        'dns_managed',
        'dns_ok',
        'ssl_ok',
        'cutover_notes',
    ];

    protected $casts = [
        'status' => DaConvertBatchItemStatus::class,
        'has_addon_sites' => 'boolean',
        'blockers' => 'array',
        'mailbox_count' => 'integer',
        'dns_managed' => 'boolean',
        'dns_ok' => 'boolean',
        'ssl_ok' => 'boolean',
        'cutover_notes' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DaConvertBatch::class, 'da_convert_batch_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(ResellerProduct::class, 'reseller_product_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

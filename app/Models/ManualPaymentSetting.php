<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManualPaymentSetting extends Model
{
    protected $table = 'manual_payment_settings';
    protected $fillable = [
        'bank_name',
        'account_name',
        'account_number',
        'branch',
        'swift_code',
    ];

    public $timestamps = true;
    const UPDATED_AT = 'updated_at';
    const CREATED_AT = null;

    /**
     * Get the current manual payment settings (always get the first/only record)
     */
    public static function getCurrent()
    {
        return self::firstOrCreate([], [
            'bank_name' => '',
            'account_name' => '',
            'account_number' => '',
            'branch' => '',
            'swift_code' => '',
        ]);
    }
}

<?php

namespace App\Support;

use App\Models\Setting;

class SharedHostingSales
{
    public static function enabled(): bool
    {
        $raw = Setting::getValue('shared_hosting_sales_enabled');
        if ($raw === null || $raw === '') {
            return (bool) config('mailcow.shared_hosting_sales_enabled_default', true);
        }

        return in_array($raw, ['1', 'true', true, 1], true);
    }
}

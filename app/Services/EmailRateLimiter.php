<?php

namespace App\Services;

use App\Enums\NotificationEvent;
use Illuminate\Support\Facades\Cache;

class EmailRateLimiter
{
    public function allow(string $recipient, NotificationEvent $event, int $maxPerHour = 20): bool
    {
        $key = 'email_rate:'.md5(strtolower($recipient).':'.$event->value);
        $count = (int) Cache::get($key, 0);

        if ($count >= $maxPerHour) {
            return false;
        }

        Cache::put($key, $count + 1, now()->addHour());

        return true;
    }
}

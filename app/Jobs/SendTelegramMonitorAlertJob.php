<?php

namespace App\Jobs;

use App\Enums\TelegramMonitorCategory;
use App\Services\Telegram\TelegramMonitorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendTelegramMonitorAlertJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, scalar|null>  $fields
     */
    public function __construct(
        public string $category,
        public string $title,
        public array $fields = [],
        public ?string $footer = null,
    ) {}

    public function handle(TelegramMonitorService $monitor): void
    {
        $category = TelegramMonitorCategory::tryFrom($this->category);

        if (! $category) {
            return;
        }

        if (! $monitor->sendNow($category, $this->title, $this->fields, $this->footer)) {
            Log::warning('Telegram monitor alert was not delivered', [
                'category' => $this->category,
                'title' => $this->title,
            ]);
        }
    }
}

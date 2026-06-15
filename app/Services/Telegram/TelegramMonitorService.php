<?php

namespace App\Services\Telegram;

use App\Enums\TelegramMonitorCategory;
use App\Jobs\SendTelegramMonitorAlertJob;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class TelegramMonitorService
{
    public function __construct(
        private TelegramBotService $bot,
    ) {}

    public function isEnabled(): bool
    {
        return $this->isTruthy(Setting::getValue('telegram_monitor_enabled', '0'))
            && $this->credentials()['token'] !== ''
            && $this->credentials()['chat_id'] !== '';
    }

    public function isCategoryEnabled(TelegramMonitorCategory $category): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        return $this->isTruthy(Setting::getValue($category->settingKey(), '1'));
    }

    /**
     * @param  array<string, scalar|null>  $fields
     */
    public function alert(TelegramMonitorCategory $category, string $title, array $fields = [], ?string $footer = null): void
    {
        if (! $this->isCategoryEnabled($category)) {
            return;
        }

        SendTelegramMonitorAlertJob::dispatch(
            $category->value,
            $title,
            $fields,
            $footer,
        );
    }

    /**
     * @param  array<string, scalar|null>  $fields
     */
    public function sendNow(TelegramMonitorCategory $category, string $title, array $fields = [], ?string $footer = null): bool
    {
        if (! $this->isCategoryEnabled($category)) {
            return false;
        }

        $credentials = $this->credentials();
        $message = $this->formatMessage($category, $title, $fields, $footer);

        try {
            return $this->bot->sendMessage($credentials['token'], $credentials['chat_id'], $message);
        } catch (\Throwable $e) {
            Log::error('Telegram monitor send failed', [
                'category' => $category->value,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array{token: string, chat_id: string}
     */
    public function credentials(): array
    {
        return [
            'token' => trim((string) Setting::getValue('telegram_bot_token', '')),
            'chat_id' => trim((string) Setting::getValue('telegram_chat_id', '')),
        ];
    }

    /**
     * @param  array<string, scalar|null>  $fields
     */
    public function formatMessage(TelegramMonitorCategory $category, string $title, array $fields = [], ?string $footer = null): string
    {
        $lines = [
            $category->icon().' <b>'.e($title).'</b>',
            '<i>'.e(now()->timezone(config('app.timezone'))->format('Y-m-d H:i:s T')).'</i>',
            '',
        ];

        foreach ($fields as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $lines[] = '<b>'.e((string) $label).':</b> '.e((string) $value);
        }

        if ($footer) {
            $lines[] = '';
            $lines[] = e($footer);
        }

        return implode("\n", $lines);
    }

    public function userContext(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $fields = [
            'Customer' => $user->name,
            'Email' => $user->email,
            'User ID' => (string) $user->id,
        ];

        if ($user->reseller_id) {
            $fields['Reseller ID'] = (string) $user->reseller_id;
        }

        if ($user->is_reseller) {
            $fields['Account type'] = 'Reseller';
        }

        return $fields;
    }

    private function isTruthy(mixed $value): bool
    {
        return in_array($value, ['1', 'true', true, 1], true);
    }
}

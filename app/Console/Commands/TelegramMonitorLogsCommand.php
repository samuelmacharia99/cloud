<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Telegram\TelegramMonitorBridge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TelegramMonitorLogsCommand extends Command
{
    protected $signature = 'telegram:monitor-logs';

    protected $description = 'Scan Laravel logs and send new ERROR/CRITICAL entries to Telegram';

    public function handle(TelegramMonitorBridge $bridge): int
    {
        $path = storage_path('logs/laravel.log');

        if (! File::exists($path)) {
            return self::SUCCESS;
        }

        $offset = (int) Setting::getValue('telegram_log_monitor_offset', '0');
        $size = File::size($path);

        if ($size < $offset) {
            $offset = 0;
        }

        if ($size === $offset) {
            return self::SUCCESS;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return self::FAILURE;
        }

        fseek($handle, $offset);
        $chunk = stream_get_contents($handle) ?: '';
        fclose($handle);

        Setting::setValue('telegram_log_monitor_offset', (string) $size);

        if ($chunk === '') {
            return self::SUCCESS;
        }

        foreach ($this->parseLogEntries($chunk) as $entry) {
            if (! in_array($entry['level'], ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)) {
                continue;
            }

            $bridge->logError($entry['level'], $entry['message'], $entry['context'] ?? null);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array{level: string, message: string, context?: string}>
     */
    private function parseLogEntries(string $chunk): array
    {
        $entries = [];
        $pattern = '/^\[([\d\-:\s]+)\]\s+(\S+)\.(\S+):\s+(.*)$/m';
        $parts = preg_split('/(?=^\[\d{4}-\d{2}-\d{2})/m', $chunk) ?: [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (! preg_match($pattern, $part, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $level = strtoupper($matches[3][0]);
            $body = substr($part, $matches[0][1] + strlen($matches[0][0]));
            $messageLine = trim($matches[4][0]);
            $context = trim($body) !== '' ? trim($body) : null;

            $entries[] = [
                'level' => $level,
                'message' => $messageLine,
                'context' => $context,
            ];
        }

        return $entries;
    }
}

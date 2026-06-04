<?php

namespace App\Support;

class ScheduleLogRotator
{
    public static function rotateIfNeeded(?string $path = null): bool
    {
        $path ??= storage_path('logs/cron.log');

        if (! is_file($path)) {
            return false;
        }

        $maxBytes = (int) config('scheduler.cron_log_max_bytes', 5 * 1024 * 1024);
        $size = filesize($path);

        if ($size === false || $size <= $maxBytes) {
            return false;
        }

        $archive = $path.'.'.now()->format('Y-m-d-His').'.bak';

        if (! @rename($path, $archive)) {
            // Fallback: truncate in place if rename fails (permissions)
            file_put_contents($path, '');

            return true;
        }

        touch($path);

        self::pruneOldArchives(dirname($path));

        return true;
    }

    private static function pruneOldArchives(string $directory): void
    {
        $files = glob($directory.'/cron.log.*.bak') ?: [];

        if (count($files) <= 3) {
            return;
        }

        usort($files, fn ($a, $b) => filemtime($a) <=> filemtime($b));

        foreach (array_slice($files, 0, count($files) - 3) as $old) {
            @unlink($old);
        }
    }
}

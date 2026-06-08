<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class BackupSettingsCommand extends Command
{
    protected $signature = 'settings:backup {--path= : Optional output path}';

    protected $description = 'Export all settings rows to a JSON backup file';

    public function handle(): int
    {
        if (! Schema::hasTable('settings')) {
            $this->warn('Settings table does not exist — skipping backup.');

            return self::SUCCESS;
        }

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'app_env' => config('app.env'),
            'count' => Setting::count(),
            'settings' => Setting::query()->orderBy('key')->get(['key', 'value', 'description'])->toArray(),
        ];

        $path = $this->option('path')
            ?: storage_path('backups/deploy/settings_'.now()->format('Y-m-d_His').'.json');

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info("Settings backup written: {$path} ({$payload['count']} rows)");

        return self::SUCCESS;
    }
}

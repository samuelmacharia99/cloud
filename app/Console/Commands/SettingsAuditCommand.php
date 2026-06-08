<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SettingsAuditCommand extends Command
{
    protected $signature = 'settings:audit {--keys= : Comma-separated keys to inspect (default: branding)}';

    protected $description = 'Show stored settings values and whether branding files exist (read-only audit)';

    public function handle(): int
    {
        $preset = $this->option('keys') ?: 'branding';

        $keys = match ($preset) {
            'branding' => ['logo_url', 'favicon_url', 'company_name', 'primary_color', 'footer_text'],
            'all' => Setting::query()->orderBy('key')->pluck('key')->all(),
            default => array_map('trim', explode(',', $preset)),
        };

        $rows = [];

        foreach ($keys as $key) {
            $value = Setting::where('key', $key)->value('value');
            $rows[] = [$key, $value ?? '(not set)', $this->fileStatus($key, $value)];
        }

        $this->table(['Key', 'Stored value', 'File status'], $rows);

        $this->newLine();
        $this->line('Production deploy backs up settings and blocks destructive artisan commands (migrate:fresh, full db:seed).');

        return self::SUCCESS;
    }

    private function fileStatus(string $key, ?string $value): string
    {
        if (! in_array($key, ['logo_url', 'favicon_url'], true)) {
            return '—';
        }

        if (empty($value)) {
            $dir = $key === 'logo_url' ? 'branding/logo' : 'branding/favicon';

            return Storage::disk('public')->exists($dir)
                ? 'No URL set; uploads exist in storage'
                : 'No URL set';
        }

        $resolved = branding_asset_url($value);

        return $resolved ? "OK → {$resolved}" : 'URL set but file missing';
    }
}

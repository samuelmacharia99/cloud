<?php

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\TelegramMonitorLogsCommand;
use App\Models\Setting;
use App\Services\Telegram\TelegramMonitorBridge;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class TelegramMonitorLogsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->text('description')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_command_alerts_on_new_error_log_lines(): void
    {
        $path = storage_path('logs/laravel.log');
        File::ensureDirectoryExists(dirname($path));

        $entry = "[2026-06-07 12:00:00] production.ERROR: Database connection failed\n{\"sql\":\"select 1\"}\n";
        file_put_contents($path, $entry);

        Setting::setValue('telegram_log_monitor_offset', '0');

        $bridge = Mockery::mock(TelegramMonitorBridge::class);
        $bridge->shouldReceive('logError')
            ->once()
            ->with('ERROR', 'Database connection failed', Mockery::type('string'));

        $this->app->instance(TelegramMonitorBridge::class, $bridge);

        $this->artisan(TelegramMonitorLogsCommand::class)->assertSuccessful();

        $this->assertSame((string) strlen($entry), Setting::getValue('telegram_log_monitor_offset'));
    }
}

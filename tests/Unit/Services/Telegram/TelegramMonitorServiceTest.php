<?php

namespace Tests\Unit\Services\Telegram;

use App\Enums\TelegramMonitorCategory;
use App\Models\Setting;
use App\Services\Telegram\TelegramMonitorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TelegramMonitorServiceTest extends TestCase
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

    public function test_is_enabled_requires_token_chat_and_toggle(): void
    {
        Setting::setValue('telegram_monitor_enabled', '0');
        Setting::setValue('telegram_bot_token', 'token');
        Setting::setValue('telegram_chat_id', '123');

        $monitor = app(TelegramMonitorService::class);
        $this->assertFalse($monitor->isEnabled());

        Setting::setValue('telegram_monitor_enabled', '1');
        $this->assertTrue($monitor->isEnabled());
    }

    public function test_format_message_escapes_html_entities(): void
    {
        $monitor = app(TelegramMonitorService::class);

        $message = $monitor->formatMessage(
            TelegramMonitorCategory::Payments,
            'Payment <received>',
            ['Customer' => 'Jane & Co', 'Amount' => '100'],
        );

        $this->assertStringContainsString('<b>Payment &lt;received&gt;</b>', $message);
        $this->assertStringContainsString('<b>Customer:</b> Jane &amp; Co', $message);
        $this->assertStringContainsString('💳', $message);
    }

    public function test_category_respects_individual_toggle(): void
    {
        Setting::setValue('telegram_monitor_enabled', '1');
        Setting::setValue('telegram_bot_token', 'token');
        Setting::setValue('telegram_chat_id', '123');
        Setting::setValue('telegram_monitor_payments', '0');

        $monitor = app(TelegramMonitorService::class);

        $this->assertFalse($monitor->isCategoryEnabled(TelegramMonitorCategory::Payments));
        $this->assertTrue($monitor->isCategoryEnabled(TelegramMonitorCategory::Services));
    }
}

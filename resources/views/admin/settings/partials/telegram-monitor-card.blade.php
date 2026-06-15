@php use App\Enums\TelegramMonitorCategory; @endphp

<form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="window.submitForm($el)">
    @csrf

    <fieldset>
        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-2">Telegram monitoring</legend>
        <p class="text-sm text-slate-600 dark:text-slate-400 mb-6">
            Receive real-time alerts for payments, service changes, new signups, support tickets, application errors, and system events.
            Create a bot with <a href="https://t.me/BotFather" target="_blank" rel="noopener" class="text-blue-600 dark:text-blue-400 hover:underline">@BotFather</a>,
            then send <code class="text-xs bg-slate-100 dark:bg-slate-800 px-1 rounded">/start</code> to your bot before testing.
            Use <a href="https://t.me/userinfobot" target="_blank" rel="noopener" class="text-blue-600 dark:text-blue-400 hover:underline">@userinfobot</a> to find your chat ID.
        </p>

        <div class="space-y-4">
            <div>
                <input type="hidden" name="settings[telegram_monitor_enabled]" value="0">
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="settings[telegram_monitor_enabled]" value="1" @checked(($settings['telegram_monitor_enabled'] ?? '0') == '1') class="rounded" />
                    <span class="text-slate-700 dark:text-slate-300">Enable Telegram monitoring alerts</span>
                </label>
            </div>

            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Bot token</label>
                    @if($settings['telegram_bot_token'] ?? false)
                        <p class="text-sm text-green-600 dark:text-green-400 mb-2">✓ Token configured</p>
                    @endif
                    <input type="password" name="settings[telegram_bot_token]" placeholder="123456789:ABC..." autocomplete="off" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white font-mono text-sm" />
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Leave blank to keep the saved token.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Chat ID</label>
                    <input type="text" name="settings[telegram_chat_id]" value="{{ $settings['telegram_chat_id'] ?? '' }}" placeholder="-1001234567890" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white font-mono text-sm" />
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Your personal chat ID or a group where the bot is a member.</p>
                </div>
            </div>

            <div class="pt-2">
                <p class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-3">Alert categories</p>
                <div class="grid sm:grid-cols-2 gap-3">
                    @foreach (TelegramMonitorCategory::cases() as $category)
                        @php $key = $category->settingKey(); @endphp
                        <div>
                            <input type="hidden" name="settings[{{ $key }}]" value="0">
                            <label class="flex items-start gap-2 text-sm">
                                <input type="checkbox" name="settings[{{ $key }}]" value="1" @checked(($settings[$key] ?? '1') == '1') class="rounded mt-0.5" />
                                <span class="text-slate-700 dark:text-slate-300">{{ $category->icon() }} {{ $category->label() }}</span>
                            </label>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-900/50 rounded-lg p-4 text-sm text-amber-900 dark:text-amber-100">
                <p class="font-semibold mb-1">Independent of email notifications</p>
                <p>These alerts use Telegram only. They fire even when customer email or SMS is not configured.</p>
            </div>

            <div class="flex flex-wrap items-center gap-3 pt-2">
                <button type="button" onclick="sendTestTelegram()" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 font-medium rounded-lg transition-colors">
                    Send test message
                </button>
                <p id="telegram_test_status" class="text-sm text-slate-600 dark:text-slate-400" style="display:none;"></p>
            </div>
        </div>
    </fieldset>

    <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
        <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
        <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            Save Telegram Settings
        </button>
    </div>
</form>

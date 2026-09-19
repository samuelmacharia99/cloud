{{-- Talksasa bulk SMS credentials for the reseller's own sender id. --}}
            <div x-show="activeTab === 'sms'" x-transition>
                <div class="ui-card overflow-hidden">
                    <div class="bg-gradient-to-r from-green-600 to-green-700 px-6 py-4">
                        <div class="flex items-center gap-3">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16h16m-16-4h16m-16-4h16M8 7h.01M3 21h18a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                            <h3 class="text-lg font-bold text-white">Talksasa Bulk SMS</h3>
                        </div>
                    </div>

                    <div class="p-6 space-y-6">
                        <form action="{{ route('reseller.settings.sms.update') }}" method="POST" class="space-y-6">
                            @csrf

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- API Key -->
                                <div>
                                    <label for="sms_api_key" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">API Key</label>
                                    <input type="password" id="sms_api_key" name="sms_api_key"
                                        value="{{ old('sms_api_key', $smsSettings['api_key'] ?? '') }}"
                                        placeholder="Your Talksasa API key" required
                                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-green-500 dark:focus:ring-green-400 text-slate-900 dark:text-white text-sm">
                                    @error('sms_api_key')
                                        <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                                <!-- Sender ID -->
                                <div>
                                    <label for="sms_sender_id" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Sender ID</label>
                                    <input type="text" id="sms_sender_id" name="sms_sender_id"
                                        value="{{ old('sms_sender_id', $smsSettings['sender_id'] ?? '') }}"
                                        placeholder="e.g., TALKSASA" maxlength="11" required
                                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-green-500 dark:focus:ring-green-400 text-slate-900 dark:text-white text-sm">
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Max 11 characters</p>
                                    @error('sms_sender_id')
                                        <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <!-- SMS Enabled -->
                            <div>
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input type="hidden" name="sms_enabled" value="0">
                                    <input type="checkbox" name="sms_enabled" value="1"
                                        {{ old('sms_enabled') || (!old() && $smsSettings['enabled'] ?? false) ? 'checked' : '' }}
                                        class="w-4 h-4 text-green-600 rounded">
                                    <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Enable SMS Notifications</span>
                                </label>
                            </div>

                            <div class="flex gap-3 pt-2">
                                <button type="submit" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition">
                                    Save SMS Settings
                                </button>
                            </div>
                        </form>

                        <!-- Test SMS -->
                        <div class="border-t border-slate-200 dark:border-slate-700 pt-6">
                            <h4 class="font-medium text-slate-900 dark:text-white mb-4">Test SMS</h4>
                            <form action="{{ route('reseller.settings.sms.test') }}" method="POST" class="flex flex-col md:flex-row gap-3">
                                @csrf
                                <input type="tel" name="phone" placeholder="Phone number with country code"
                                    class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-green-500 dark:focus:ring-green-400 text-slate-900 dark:text-white text-sm">
                                <button type="submit" class="px-6 py-2 bg-slate-600 hover:bg-slate-700 text-white font-medium rounded-lg transition">
                                    Send Test SMS
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

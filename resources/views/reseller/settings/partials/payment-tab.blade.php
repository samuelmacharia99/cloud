{{-- M-Pesa and other payment credentials the reseller collects with. --}}
            <div x-show="activeTab === 'payment'" x-transition>
                <!-- M-Pesa Settings -->
                <div class="ui-card overflow-hidden mb-6">
                    <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-4">
                        <div class="flex items-center gap-3">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <h3 class="text-lg font-bold text-white">M-Pesa Configuration</h3>
                        </div>
                    </div>

                    <div class="p-6 space-y-6">
                        <form action="{{ route('reseller.settings.mpesa.update') }}" method="POST" class="space-y-6">
                            @csrf

                            <!-- Business Shortcode -->
                            <div>
                                <label for="mpesa_business_shortcode" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Business Shortcode</label>
                                <input type="text" id="mpesa_business_shortcode" name="mpesa_business_shortcode"
                                    value="{{ old('mpesa_business_shortcode', $mpesaSettings['business_shortcode'] ?? '') }}"
                                    placeholder="e.g., 174379" required
                                    class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                                @error('mpesa_business_shortcode')
                                    <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- Consumer Key -->
                                <div>
                                    <label for="mpesa_consumer_key" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Consumer Key</label>
                                    <input type="text" id="mpesa_consumer_key" name="mpesa_consumer_key"
                                        value="{{ old('mpesa_consumer_key', $mpesaSettings['consumer_key'] ?? '') }}"
                                        placeholder="Your consumer key" required
                                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                                    @error('mpesa_consumer_key')
                                        <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                                <!-- Consumer Secret -->
                                <div>
                                    <label for="mpesa_consumer_secret" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Consumer Secret</label>
                                    <input type="password" id="mpesa_consumer_secret" name="mpesa_consumer_secret"
                                        value="{{ old('mpesa_consumer_secret', $mpesaSettings['consumer_secret'] ?? '') }}"
                                        placeholder="Your consumer secret" required
                                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                                    @error('mpesa_consumer_secret')
                                        <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- Passkey -->
                                <div>
                                    <label for="mpesa_passkey" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Passkey</label>
                                    <input type="password" id="mpesa_passkey" name="mpesa_passkey"
                                        value="{{ old('mpesa_passkey', $mpesaSettings['passkey'] ?? '') }}"
                                        placeholder="Your passkey" required
                                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                                    @error('mpesa_passkey')
                                        <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <!-- Callback URLs -->
                            <div class="border-t border-slate-200 dark:border-slate-700 pt-4">
                                <h4 class="font-medium text-slate-900 dark:text-white mb-4">Webhook URLs</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <!-- Callback URL -->
                                    <div>
                                        <label for="mpesa_callback_url" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Callback URL</label>
                                        <input type="url" id="mpesa_callback_url" name="mpesa_callback_url"
                                            value="{{ old('mpesa_callback_url', $mpesaSettings['callback_url'] ?? '') }}"
                                            placeholder="https://example.com/callback"
                                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                                        @error('mpesa_callback_url')
                                            <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <!-- Timeout URL -->
                                    <div>
                                        <label for="mpesa_timeout_url" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Timeout URL</label>
                                        <input type="url" id="mpesa_timeout_url" name="mpesa_timeout_url"
                                            value="{{ old('mpesa_timeout_url', $mpesaSettings['timeout_url'] ?? '') }}"
                                            placeholder="https://example.com/timeout"
                                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                                        @error('mpesa_timeout_url')
                                            <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="flex gap-3 pt-2">
                                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                                    Save M-Pesa Settings
                                </button>
                            </div>
                        </form>

                        <!-- Register URLs Section -->
                        <div class="border-t border-slate-200 dark:border-slate-700 pt-6">
                            <h4 class="font-medium text-slate-900 dark:text-white mb-4">Register M-Pesa Webhook URLs</h4>
                            <form action="{{ route('reseller.settings.mpesa.register-urls') }}" method="POST" class="flex flex-col md:flex-row gap-3">
                                @csrf
                                <input type="hidden" name="callback_url" value="{{ $mpesaSettings['callback_url'] ?? '' }}">
                                <input type="hidden" name="timeout_url" value="{{ $mpesaSettings['timeout_url'] ?? '' }}">
                                <button type="submit" class="px-6 py-2 bg-slate-600 hover:bg-slate-700 text-white font-medium rounded-lg transition">
                                    Register URLs
                                </button>
                                <p class="text-sm text-slate-600 dark:text-slate-400">Click to register webhook URLs with Talksasa M-Pesa</p>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

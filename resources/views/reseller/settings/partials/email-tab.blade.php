{{-- SMTP delivery plus the reseller's customer-facing email templates. --}}
            <div x-show="activeTab === 'email'" x-transition>
                <div class="ui-card overflow-hidden">
                    <div class="bg-gradient-to-r from-purple-600 to-purple-700 px-6 py-4">
                        <div class="flex items-center gap-3">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                            <h3 class="text-lg font-bold text-white">SMTP Configuration</h3>
                        </div>
                    </div>

                    <div class="p-6 space-y-6">
                        <div class="p-4 rounded-lg border border-purple-200 dark:border-purple-800 bg-purple-50 dark:bg-purple-950/30">
                            <p class="text-sm text-purple-900 dark:text-purple-200">
                                Configure SMTP to send email to <strong>your customers</strong> — invoices, welcome messages, login codes, tickets, and service notifications.
                                Your customers never use the platform mail server; you must enable SMTP here for those emails to send.
                            </p>
                        </div>

                        <form action="{{ route('reseller.settings.smtp.update') }}" method="POST" class="space-y-6">
                            @csrf

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- SMTP Host -->
                                <div>
                                    <label for="smtp_host" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">SMTP Host</label>
                                    <input type="text" id="smtp_host" name="smtp_host"
                                        value="{{ old('smtp_host', $smtpSettings['host'] ?? '') }}"
                                        placeholder="e.g., smtp.gmail.com" required
                                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white text-sm">
                                    @error('smtp_host')
                                        <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                                <!-- SMTP Port -->
                                <div>
                                    <label for="smtp_port" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">SMTP Port</label>
                                    <input type="number" id="smtp_port" name="smtp_port"
                                        value="{{ old('smtp_port', $smtpSettings['port'] ?? 587) }}"
                                        placeholder="587 or 465" required
                                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white text-sm">
                                    @error('smtp_port')
                                        <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- SMTP Username -->
                                <div>
                                    <label for="smtp_username" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Username/Email</label>
                                    <input type="text" id="smtp_username" name="smtp_username"
                                        value="{{ old('smtp_username', $smtpSettings['username'] ?? '') }}"
                                        placeholder="your-email@gmail.com" required
                                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white text-sm">
                                    @error('smtp_username')
                                        <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                                <!-- SMTP Password -->
                                <div>
                                    <label for="smtp_password" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Password</label>
                                    @if($smtpSettings['password_configured'] ?? false)
                                        <p class="text-xs text-slate-500 dark:text-slate-400 mb-2">A password is saved. Leave blank to keep the existing password.</p>
                                    @endif
                                    <input type="password" id="smtp_password" name="smtp_password"
                                        value="{{ old('smtp_password') }}"
                                        placeholder="{{ ($smtpSettings['password_configured'] ?? false) ? 'Leave blank to keep existing' : 'Your SMTP password' }}"
                                        autocomplete="new-password"
                                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white text-sm">
                                    @error('smtp_password')
                                        <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <!-- Encryption -->
                            <div>
                                <label for="smtp_encryption" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Encryption</label>
                                <select id="smtp_encryption" name="smtp_encryption" required
                                    class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white text-sm">
                                    <option value="tls" {{ old('smtp_encryption', $smtpSettings['encryption'] ?? 'tls') === 'tls' ? 'selected' : '' }}>TLS (STARTTLS — port 587)</option>
                                    <option value="ssl" {{ old('smtp_encryption', $smtpSettings['encryption'] ?? 'tls') === 'ssl' ? 'selected' : '' }}>SSL (port 465)</option>
                                    <option value="" {{ old('smtp_encryption', $smtpSettings['encryption'] ?? 'tls') === '' ? 'selected' : '' }}>None (plain — port 25)</option>
                                </select>
                                @error('smtp_encryption')
                                    <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- From Address -->
                                <div>
                                    <label for="smtp_from_address" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">From Address</label>
                                    <input type="email" id="smtp_from_address" name="smtp_from_address"
                                        value="{{ old('smtp_from_address', $smtpSettings['from_address'] ?? '') }}"
                                        placeholder="noreply@yourdomain.com" required
                                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white text-sm">
                                    @error('smtp_from_address')
                                        <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                                <!-- From Name -->
                                <div>
                                    <label for="smtp_from_name" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">From Name</label>
                                    <input type="text" id="smtp_from_name" name="smtp_from_name"
                                        value="{{ old('smtp_from_name', $smtpSettings['from_name'] ?? '') }}"
                                        placeholder="Your Company Name" required
                                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white text-sm">
                                    @error('smtp_from_name')
                                        <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <!-- SMTP Enabled -->
                            <div>
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input type="hidden" name="smtp_enabled" value="0">
                                    <input type="checkbox" name="smtp_enabled" value="1"
                                        {{ filter_var(old('smtp_enabled', $smtpSettings['enabled'] ?? false), FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}
                                        class="w-4 h-4 text-purple-600 rounded">
                                    <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Enable SMTP for customer emails</span>
                                </label>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Must be enabled and saved before customer notifications or test emails will send.</p>
                            </div>

                            <div class="flex gap-3 pt-2">
                                <button type="submit" class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">
                                    Save SMTP Settings
                                </button>
                            </div>
                        </form>

                        <!-- Test SMTP -->
                        <div class="border-t border-slate-200 dark:border-slate-700 pt-6">
                            <h4 class="font-medium text-slate-900 dark:text-white mb-2">Test SMTP Connection</h4>
                            <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Save your settings with <strong>Enable SMTP</strong> checked first, then send a test message.</p>
                            <form action="{{ route('reseller.settings.smtp.test') }}" method="POST" class="flex flex-col md:flex-row gap-3">
                                @csrf
                                <input type="email" name="test_email" placeholder="Test email address"
                                    class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white text-sm">
                                <button type="submit" class="px-6 py-2 bg-slate-600 hover:bg-slate-700 text-white font-medium rounded-lg transition">
                                    Send Test Email
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                @include('reseller.settings.partials.email-templates-card')
            </div>

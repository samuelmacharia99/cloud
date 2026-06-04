@extends('layouts.reseller')

@section('title', 'Settings')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('dashboard') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Dashboard</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Settings</p>
</div>
@endsection

@section('content')
<div class="space-y-8" x-data="settingsTabs()" x-init="activeTab = 'payment'">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Settings</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Configure your payment gateways, SMS service, and email settings.</p>
    </div>

    <!-- Flash Messages -->
    @if ($errors->any())
        <div class="bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800 rounded-xl p-4">
            <p class="text-sm font-medium text-red-800 dark:text-red-300 mb-2">There were errors with your submission:</p>
            <ul class="list-disc list-inside space-y-1">
                @foreach ($errors->all() as $error)
                    <li class="text-sm text-red-700 dark:text-red-400">{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (session('success'))
        <div class="bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 rounded-xl p-4">
            <p class="text-sm text-emerald-800 dark:text-emerald-300">{{ session('success') }}</p>
        </div>
    @endif

    @if (session('error'))
        <div class="bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800 rounded-xl p-4">
            <p class="text-sm font-medium text-red-800 dark:text-red-300 mb-1">Action failed</p>
            <p class="text-sm text-red-700 dark:text-red-400 whitespace-pre-wrap break-words">{{ session('error') }}</p>
        </div>
    @endif

    <!-- Tab Navigation -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800">
        <div class="flex border-b border-slate-200 dark:border-slate-800">
            <!-- Payment Gateways Tab -->
            <button @click="activeTab = 'payment'" :class="activeTab === 'payment' ? 'border-b-2 border-blue-500 text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-950/30' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="flex-1 px-6 py-4 font-medium transition flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>Payment</span>
            </button>

            <!-- SMS Tab -->
            <button @click="activeTab = 'sms'" :class="activeTab === 'sms' ? 'border-b-2 border-green-500 text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-950/30' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="flex-1 px-6 py-4 font-medium transition flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16h16m-16-4h16m-16-4h16M8 7h.01M3 21h18a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                </svg>
                <span>SMS</span>
            </button>

            <!-- Email Tab -->
            <button @click="activeTab = 'email'" :class="activeTab === 'email' ? 'border-b-2 border-purple-500 text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-950/30' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="flex-1 px-6 py-4 font-medium transition flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                <span>Email</span>
            </button>

            <!-- Branding Tab -->
            <button @click="activeTab = 'branding'" :class="activeTab === 'branding' ? 'border-b-2 border-amber-500 text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/30' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="flex-1 px-6 py-4 font-medium transition flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/>
                </svg>
                <span>Branding</span>
            </button>
        </div>

        <!-- Tab Content -->
        <div class="p-8">
            <!-- Payment Gateways Tab Content -->
            <div x-show="activeTab === 'payment'" x-transition>
                <!-- M-Pesa Settings -->
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden mb-6">
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

            <!-- SMS Tab Content -->
            <div x-show="activeTab === 'sms'" x-transition>
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
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

            <!-- Email Tab Content -->
            <div x-show="activeTab === 'email'" x-transition>
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                    <div class="bg-gradient-to-r from-purple-600 to-purple-700 px-6 py-4">
                        <div class="flex items-center gap-3">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                            <h3 class="text-lg font-bold text-white">SMTP Configuration</h3>
                        </div>
                    </div>

                    <div class="p-6 space-y-6">
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
                                    <input type="password" id="smtp_password" name="smtp_password"
                                        value="{{ old('smtp_password', $smtpSettings['password'] ?? '') }}"
                                        placeholder="Your password" required
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
                                    <option value="tls" {{ old('smtp_encryption', $smtpSettings['encryption'] ?? 'tls') === 'tls' ? 'selected' : '' }}>TLS</option>
                                    <option value="ssl" {{ old('smtp_encryption', $smtpSettings['encryption'] ?? 'tls') === 'ssl' ? 'selected' : '' }}>SSL</option>
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
                                        {{ old('smtp_enabled') || (!old() && $smtpSettings['enabled'] ?? false) ? 'checked' : '' }}
                                        class="w-4 h-4 text-purple-600 rounded">
                                    <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Enable SMTP</span>
                                </label>
                            </div>

                            <div class="flex gap-3 pt-2">
                                <button type="submit" class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">
                                    Save SMTP Settings
                                </button>
                            </div>
                        </form>

                        <!-- Test SMTP -->
                        <div class="border-t border-slate-200 dark:border-slate-700 pt-6">
                            <h4 class="font-medium text-slate-900 dark:text-white mb-4">Test SMTP Connection</h4>
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
            </div>

            <!-- Branding Tab Content -->
            <div x-show="activeTab === 'branding'" x-transition>
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                    <div class="bg-gradient-to-r from-amber-600 to-amber-700 px-6 py-4">
                        <div class="flex items-center gap-3">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/>
                            </svg>
                            <h3 class="text-lg font-bold text-white">Branding Settings</h3>
                        </div>
                    </div>

                    <div class="p-6 space-y-6">
                        @if(!empty($brandingStatus))
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @foreach($brandingStatus as $key => $item)
                                <div class="flex items-start gap-3 p-4 rounded-lg border {{ $item['ready'] ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/30' : 'border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800/30' }}">
                                    <span class="text-lg">{{ $item['ready'] ? '✓' : '○' }}</span>
                                    <div>
                                        <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $item['label'] }}</p>
                                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ $item['hint'] }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @endif

                        @if(!empty($registrationInviteUrl))
                        <div class="p-4 rounded-lg border border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950/30">
                            <p class="text-sm font-medium text-slate-900 dark:text-white mb-2">Customer registration invite link</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">Share this link so new customers register under your brand and are linked to your account.</p>
                            <input type="text" readonly value="{{ $registrationInviteUrl }}" class="w-full px-3 py-2 text-xs font-mono border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-700 dark:text-slate-300">
                        </div>
                        @endif

                        <!-- Company Name Form -->
                        <form action="{{ route('reseller.settings.branding.update') }}" method="POST" class="space-y-6">
                            @csrf

                            <!-- Company Name -->
                            <div>
                                <label for="company_name" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Company Name</label>
                                <input type="text" id="company_name" name="company_name"
                                    value="{{ old('company_name', $brandingSettings['company_name'] ?? '') }}"
                                    placeholder="e.g., Acme Hosting" required
                                    class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-amber-500 dark:focus:ring-amber-400 text-slate-900 dark:text-white text-sm">
                                @error('company_name')
                                    <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Tagline -->
                            <div>
                                <label for="tagline" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Tagline</label>
                                <input type="text" id="tagline" name="tagline"
                                    value="{{ old('tagline', $brandingSettings['tagline'] ?? '') }}"
                                    placeholder="e.g., Reliable hosting for your business"
                                    class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-amber-500 dark:focus:ring-amber-400 text-slate-900 dark:text-white text-sm">
                            </div>

                            <!-- Primary Color -->
                            <div>
                                <label for="primary_color" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Primary Color</label>
                                <input type="color" id="primary_color" name="primary_color"
                                    value="{{ old('primary_color', $brandingSettings['primary_color'] ?? '#7c3aed') }}"
                                    class="h-10 w-20 border border-slate-300 dark:border-slate-600 rounded-lg cursor-pointer">
                            </div>

                            <!-- Support Email -->
                            <div>
                                <label for="support_email" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Support Email</label>
                                <input type="email" id="support_email" name="support_email"
                                    value="{{ old('support_email', $brandingSettings['support_email'] ?? '') }}"
                                    placeholder="support@yourcompany.com"
                                    class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-amber-500 dark:focus:ring-amber-400 text-slate-900 dark:text-white text-sm">
                            </div>

                            <!-- Support Phone -->
                            <div>
                                <label for="support_phone" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Support Phone</label>
                                <input type="text" id="support_phone" name="support_phone"
                                    value="{{ old('support_phone', $brandingSettings['support_phone'] ?? '') }}"
                                    placeholder="+254..."
                                    class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-amber-500 dark:focus:ring-amber-400 text-slate-900 dark:text-white text-sm">
                            </div>

                            <!-- Footer Text -->
                            <div>
                                <label for="footer_text" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Footer Text</label>
                                <textarea id="footer_text" name="footer_text" rows="2"
                                    placeholder="Shown in emails and customer portal footer"
                                    class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-amber-500 dark:focus:ring-amber-400 text-slate-900 dark:text-white text-sm">{{ old('footer_text', $brandingSettings['footer_text'] ?? '') }}</textarea>
                            </div>

                            <!-- Custom Domain -->
                            @php
                                $sslStatus = $brandingSettings['ssl'] ?? [];
                                $savedCustomDomain = $brandingSettings['custom_domain'] ?? null;
                            @endphp
                            <div>
                                <label for="custom_domain" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Custom Domain</label>
                                <input type="text" id="custom_domain" name="custom_domain"
                                    value="{{ old('custom_domain', $savedCustomDomain ?? '') }}"
                                    placeholder="e.g., billing.acme.com"
                                    class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-amber-500 dark:focus:ring-amber-400 text-slate-900 dark:text-white text-sm">
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Your customers can access your portal via your custom domain. Point a CNAME or A record to this server, save, then check DNS and provision SSL.</p>
                                @error('custom_domain')
                                    <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                @enderror

                                @if(!empty($savedCustomDomain))
                                <div class="mt-4 rounded-lg border border-slate-200 dark:border-slate-700 p-4 space-y-4" x-data="sslChecker()">
                                    <div>
                                        <h4 class="text-sm font-semibold text-slate-900 dark:text-white">DNS &amp; SSL for <span class="font-mono text-amber-700 dark:text-amber-300">{{ $savedCustomDomain }}</span></h4>
                                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Save branding first if you changed the domain above. Background SSL also runs on a schedule when a queue worker is active.</p>
                                    </div>

                                    <div class="bg-slate-50 dark:bg-slate-800/30 p-4 rounded-lg border border-slate-200 dark:border-slate-700">
                                        <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
                                            After DNS points here, use <strong>Check DNS</strong>, then <strong>Provision SSL</strong> to issue your certificate immediately.
                                        </p>
                                        <div class="flex flex-wrap items-center gap-3">
                                            <button type="button" @click="checkDns()" :disabled="checking"
                                                class="px-4 py-2 bg-slate-600 hover:bg-slate-700 disabled:bg-slate-500 text-white text-sm font-medium rounded-lg transition">
                                                <span x-show="!checking">Check DNS</span>
                                                <span x-show="checking">Checking...</span>
                                            </button>
                                            <form action="{{ route('reseller.settings.branding.ssl.provision') }}" method="POST" class="inline">
                                                @csrf
                                                <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-lg transition inline-flex items-center gap-2">
                                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                                    Provision SSL
                                                </button>
                                            </form>
                                        </div>

                                        <div x-show="dnsChecked && dnsResult" class="mt-4 space-y-2">
                                            <div class="text-sm">
                                                <p class="text-slate-600 dark:text-slate-400">Server IP: <span class="font-mono text-slate-900 dark:text-white">{{ substr(gethostbyname(parse_url(config('app.url'), PHP_URL_HOST)), 0, 50) }}</span></p>
                                                <p class="text-slate-600 dark:text-slate-400" x-show="dnsResult?.domain_ip">
                                                    Domain IP: <span class="font-mono text-slate-900 dark:text-white" x-text="dnsResult?.domain_ip ?? '—'"></span>
                                                </p>
                                            </div>
                                            <p class="text-sm text-red-600 dark:text-red-400" x-show="dnsResult && dnsResult.success === false" x-text="dnsResult?.message ?? 'DNS check failed.'"></p>
                                            <p class="text-sm text-red-600 dark:text-red-400" x-show="dnsResult && dnsResult.success !== false && !dnsResult.match">
                                                ✗ {{ $savedCustomDomain }} is not pointing to this server
                                            </p>
                                            <p class="text-sm text-emerald-600 dark:text-emerald-400" x-show="dnsResult && dnsResult.match">
                                                ✓ DNS is correctly configured
                                            </p>
                                            <p class="text-sm text-slate-600 dark:text-slate-400" x-show="dnsResult && dnsResult.certbot_available === false">
                                                ⚠️ certbot is not installed on this server. Ask your administrator to install it.
                                            </p>
                                        </div>
                                        <p x-show="dnsChecked && !dnsResult" class="mt-4 text-sm text-red-600 dark:text-red-400">DNS check failed. Please try again.</p>
                                    </div>

                                    <div class="space-y-3">
                                        @if(in_array($sslStatus['status'] ?? 'none', ['pending', 'provisioning']))
                                            <div class="bg-blue-50 dark:bg-blue-950/30 p-3 rounded-lg border border-blue-200 dark:border-blue-800">
                                                <p class="text-sm font-medium text-blue-900 dark:text-blue-300">SSL provisioning in progress</p>
                                                <p class="text-xs text-blue-700 dark:text-blue-400 mt-1">Refresh this page in a few minutes. If it stays stuck, run Check DNS and Provision SSL again.</p>
                                            </div>
                                        @elseif($sslStatus['status'] === 'active')
                                            <div class="flex flex-wrap items-center justify-between gap-3 bg-emerald-50 dark:bg-emerald-950/30 p-3 rounded-lg border border-emerald-200 dark:border-emerald-800">
                                                <div>
                                                    <p class="text-sm font-medium text-emerald-900 dark:text-emerald-300">SSL certificate active</p>
                                                    @if($sslStatus['expires_at'])
                                                        <p class="text-xs text-emerald-700 dark:text-emerald-400">
                                                            Expires: {{ \Carbon\Carbon::parse($sslStatus['expires_at'])->format('M d, Y') }}
                                                        </p>
                                                    @endif
                                                </div>
                                                <form action="{{ route('reseller.settings.branding.ssl.renew') }}" method="POST">
                                                    @csrf
                                                    <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-lg transition">
                                                        Renew certificate
                                                    </button>
                                                </form>
                                            </div>
                                        @elseif($sslStatus['status'] === 'failed')
                                            @php
                                                $sslError = trim((string) ($sslStatus['error'] ?? ''));
                                                $sslOutput = trim((string) ($sslStatus['last_output'] ?? ''));
                                                if ($sslError === '' && $sslOutput !== '') {
                                                    $sslError = \Illuminate\Support\Str::limit($sslOutput, 500);
                                                }
                                            @endphp
                                            <div class="bg-red-50 dark:bg-red-950/30 p-3 rounded-lg border border-red-200 dark:border-red-800 space-y-2">
                                                <p class="text-sm font-medium text-red-900 dark:text-red-300">SSL setup failed</p>
                                                @if($sslError !== '')
                                                    <p class="text-xs font-medium text-red-800 dark:text-red-300">Error</p>
                                                    <p class="text-xs text-red-700 dark:text-red-400 whitespace-pre-wrap break-words">{{ $sslError }}</p>
                                                @endif
                                                @if($sslOutput !== '' && $sslOutput !== $sslError)
                                                    <p class="text-xs font-medium text-red-800 dark:text-red-300 pt-1">Certbot output</p>
                                                    <pre class="text-xs text-red-800 dark:text-red-300 whitespace-pre-wrap break-words max-h-48 overflow-y-auto rounded-md bg-red-100/80 dark:bg-red-950/50 p-2 border border-red-200 dark:border-red-900">{{ $sslOutput }}</pre>
                                                @endif
                                                @if($sslError === '' && $sslOutput === '')
                                                    <p class="text-xs text-red-700 dark:text-red-400">No error details were saved. Run Provision SSL again or check storage/logs/laravel.log on the server.</p>
                                                @endif
                                                <p class="text-xs text-red-700 dark:text-red-400 pt-1">Fix DNS if needed, then use Provision SSL above.</p>
                                            </div>
                                        @else
                                            <div class="bg-amber-50 dark:bg-amber-950/30 p-3 rounded-lg border border-amber-200 dark:border-amber-800">
                                                <p class="text-sm font-medium text-amber-900 dark:text-amber-300">SSL not issued yet</p>
                                                <p class="text-xs text-amber-700 dark:text-amber-400 mt-1">Use Check DNS and Provision SSL above once your domain points to this server.</p>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                                @endif
                            </div>

                            <div class="flex flex-wrap gap-3">
                                <button type="submit" class="px-6 py-2 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg transition">
                                    Save Branding Settings
                                </button>
                            </div>
                        </form>

                        <!-- Logo Upload -->
                        <div class="border-t border-slate-200 dark:border-slate-700 pt-6">
                            <h4 class="font-medium text-slate-900 dark:text-white mb-4">Logo</h4>
                            <div class="space-y-4">
                                @if(!empty($brandingSettings['logo_url']))
                                    <div class="flex items-center justify-between bg-slate-50 dark:bg-slate-800/50 p-4 rounded-lg border border-slate-200 dark:border-slate-700">
                                        <div class="flex items-center gap-3">
                                            <img src="{{ branding_asset_url($brandingSettings['logo_url']) }}" alt="Logo" class="h-12 w-auto max-w-[120px] object-contain">
                                            <div>
                                                <p class="text-sm font-medium text-slate-900 dark:text-white">Current Logo</p>
                                                <p class="text-xs text-slate-500 dark:text-slate-400">Recommended size: 500x150px</p>
                                            </div>
                                        </div>
                                        <form action="{{ route('reseller.settings.branding.delete') }}" method="POST" class="flex">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="type" value="logo">
                                            <button type="submit" class="px-4 py-2 bg-red-100 hover:bg-red-200 text-red-700 text-sm font-medium rounded-lg transition">
                                                Remove
                                            </button>
                                        </form>
                                    </div>
                                @endif
                                <form action="{{ route('reseller.settings.branding.upload') }}" method="POST" enctype="multipart/form-data" class="flex flex-col gap-2">
                                    @csrf
                                    <input type="hidden" name="type" value="logo">
                                    <div class="relative">
                                        <label for="logo_file" class="flex items-center justify-center w-full px-4 py-3 border-2 border-dashed border-slate-300 dark:border-slate-600 rounded-lg cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/50 transition">
                                            <div class="text-center">
                                                <svg class="mx-auto h-8 w-8 text-slate-400 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                                </svg>
                                                <p class="text-sm text-slate-600 dark:text-slate-400">Click to upload or drag and drop</p>
                                                <p class="text-xs text-slate-500 dark:text-slate-500">PNG, JPG, GIF or WebP (max 2MB)</p>
                                            </div>
                                            <input id="logo_file" name="file" type="file" class="hidden" accept="image/*" required>
                                        </label>
                                    </div>
                                    <button type="submit" class="px-6 py-2 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg transition">
                                        Upload Logo
                                    </button>
                                    @error('file')
                                        <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </form>
                            </div>
                        </div>

                        <!-- Favicon Upload -->
                        <div class="border-t border-slate-200 dark:border-slate-700 pt-6">
                            <h4 class="font-medium text-slate-900 dark:text-white mb-4">Favicon</h4>
                            <div class="space-y-4">
                                @if(!empty($brandingSettings['favicon_url']))
                                    <div class="flex items-center justify-between bg-slate-50 dark:bg-slate-800/50 p-4 rounded-lg border border-slate-200 dark:border-slate-700">
                                        <div class="flex items-center gap-3">
                                            <img src="{{ branding_asset_url($brandingSettings['favicon_url']) }}" alt="Favicon" class="h-8 w-8 object-contain">
                                            <div>
                                                <p class="text-sm font-medium text-slate-900 dark:text-white">Current Favicon</p>
                                                <p class="text-xs text-slate-500 dark:text-slate-400">Recommended size: 32x32px or 64x64px</p>
                                            </div>
                                        </div>
                                        <form action="{{ route('reseller.settings.branding.delete') }}" method="POST" class="flex">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="type" value="favicon">
                                            <button type="submit" class="px-4 py-2 bg-red-100 hover:bg-red-200 text-red-700 text-sm font-medium rounded-lg transition">
                                                Remove
                                            </button>
                                        </form>
                                    </div>
                                @endif
                                <form action="{{ route('reseller.settings.branding.upload') }}" method="POST" enctype="multipart/form-data" class="flex flex-col gap-2">
                                    @csrf
                                    <input type="hidden" name="type" value="favicon">
                                    <div class="relative">
                                        <label for="favicon_file" class="flex items-center justify-center w-full px-4 py-3 border-2 border-dashed border-slate-300 dark:border-slate-600 rounded-lg cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/50 transition">
                                            <div class="text-center">
                                                <svg class="mx-auto h-8 w-8 text-slate-400 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                                </svg>
                                                <p class="text-sm text-slate-600 dark:text-slate-400">Click to upload or drag and drop</p>
                                                <p class="text-xs text-slate-500 dark:text-slate-500">PNG, ICO or GIF (max 2MB)</p>
                                            </div>
                                            <input id="favicon_file" name="file" type="file" class="hidden" accept="image/*" required>
                                        </label>
                                    </div>
                                    <button type="submit" class="px-6 py-2 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg transition">
                                        Upload Favicon
                                    </button>
                                    @error('file')
                                        <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </form>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function settingsTabs() {
    return {
        activeTab: 'payment',
    }
}

function sslChecker() {
    return {
        checking: false,
        dnsChecked: false,
        dnsResult: null,
        checkDns() {
            this.checking = true;
            const domain = '{{ $savedCustomDomain ?? $brandingSettings['custom_domain'] ?? '' }}';

            if (!domain) {
                alert('Please save a custom domain first.');
                this.checking = false;
                return;
            }

            fetch(`{{ route('reseller.settings.branding.ssl.check-dns') }}?domain=${encodeURIComponent(domain)}`)
                .then(response => response.json())
                .then(data => {
                    this.dnsResult = data?.success === false ? data : { ...data, certbot_available: data.certbot_available ?? false };
                    this.dnsChecked = true;
                    this.checking = false;
                })
                .catch(error => {
                    console.error('Error checking DNS:', error);
                    this.dnsResult = null;
                    this.dnsChecked = true;
                    this.checking = false;
                    alert('Failed to check DNS. Please try again.');
                });
        }
    }
}
</script>
@endsection

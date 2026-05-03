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
<div class="space-y-8 max-w-4xl">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Settings</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Configure your payment gateways, SMS service, and email settings.</p>
    </div>

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

    <!-- M-Pesa Settings -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-4">
            <div class="flex items-center gap-3">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <h2 class="text-xl font-bold text-white">M-Pesa Configuration</h2>
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

                <!-- Passkey -->
                <div>
                    <label for="mpesa_passkey" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Passkey</label>
                    <input type="password" id="mpesa_passkey" name="mpesa_passkey"
                        value="{{ old('mpesa_passkey', $mpesaSettings['passkey'] ?? '') }}"
                        placeholder="Your M-Pesa passkey" required
                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                    @error('mpesa_passkey')
                        <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Callback URL -->
                    <div>
                        <label for="mpesa_callback_url" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Callback URL</label>
                        <input type="url" id="mpesa_callback_url" name="mpesa_callback_url"
                            value="{{ old('mpesa_callback_url', $mpesaSettings['callback_url'] ?? '') }}"
                            placeholder="https://yourdomain.com/callback"
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
                            placeholder="https://yourdomain.com/timeout"
                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        @error('mpesa_timeout_url')
                            <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                        Save M-Pesa Settings
                    </button>
                    <form action="{{ route('reseller.settings.mpesa.register-urls') }}" method="POST" class="inline">
                        @csrf
                        <input type="hidden" name="callback_url" value="{{ $mpesaSettings['callback_url'] ?? '' }}">
                        <input type="hidden" name="timeout_url" value="{{ $mpesaSettings['timeout_url'] ?? '' }}">
                        <button type="submit" class="px-6 py-2 bg-slate-600 hover:bg-slate-700 text-white font-medium rounded-lg transition">
                            Register URLs
                        </button>
                    </form>
                </div>
            </form>
        </div>
    </div>

    <!-- SMS Settings -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="bg-gradient-to-r from-green-600 to-green-700 px-6 py-4">
            <div class="flex items-center gap-3">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16h16m-16-4h16m-16-4h16M8 7h.01M3 21h18a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                </svg>
                <h2 class="text-xl font-bold text-white">Talksasa Bulk SMS Configuration</h2>
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
                        <input type="checkbox" name="sms_enabled"
                            {{ old('sms_enabled', $smsSettings['enabled'] ?? false) ? 'checked' : '' }}
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
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Test SMS</h3>
                <form action="{{ route('reseller.settings.sms.test') }}" method="POST" class="flex gap-3">
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

    <!-- SMTP Settings -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="bg-gradient-to-r from-purple-600 to-purple-700 px-6 py-4">
            <div class="flex items-center gap-3">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                <h2 class="text-xl font-bold text-white">SMTP Configuration</h2>
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
                            placeholder="Your SMTP password" required
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
                        <input type="checkbox" name="smtp_enabled"
                            {{ old('smtp_enabled', $smtpSettings['enabled'] ?? false) ? 'checked' : '' }}
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
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Test SMTP Connection</h3>
                <form action="{{ route('reseller.settings.smtp.test') }}" method="POST" class="flex gap-3">
                    @csrf
                    <input type="email" name="test_email" placeholder="test@example.com"
                        class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white text-sm">
                    <button type="submit" class="px-6 py-2 bg-slate-600 hover:bg-slate-700 text-white font-medium rounded-lg transition">
                        Send Test Email
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

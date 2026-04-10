@extends('layouts.admin')

@section('title', 'Settings')

@section('breadcrumb')
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Settings</p>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Platform Settings</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Configure system-wide settings and preferences.</p>
    </div>

    <!-- Settings Form with Tabs -->
    <div x-data="{ activeTab: 'general' }" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800">
        <!-- Tab Navigation -->
        <div class="border-b border-slate-200 dark:border-slate-800 overflow-x-auto">
            <div class="flex gap-1 px-6 min-w-max">
                <button @click="activeTab = 'general'" :class="activeTab === 'general' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">
                    General
                </button>
                <button @click="activeTab = 'billing'" :class="activeTab === 'billing' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">
                    Billing
                </button>
                <button @click="activeTab = 'tax'" :class="activeTab === 'tax' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">
                    Tax
                </button>
                <button @click="activeTab = 'payment_methods'" :class="activeTab === 'payment_methods' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">
                    Payment Methods
                </button>
                <button @click="activeTab = 'provisioning'" :class="activeTab === 'provisioning' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">
                    Provisioning
                </button>
                <button @click="activeTab = 'branding'" :class="activeTab === 'branding' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">
                    Branding
                </button>
                <button @click="activeTab = 'email'" :class="activeTab === 'email' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">
                    Email
                </button>
                <button @click="activeTab = 'notifications'" :class="activeTab === 'notifications' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">
                    Notifications
                </button>
                <button @click="activeTab = 'cron'" :class="activeTab === 'cron' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">
                    Cron Jobs
                </button>
                <button @click="activeTab = 'sms'" :class="activeTab === 'sms' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">
                    SMS
                </button>
                <button @click="activeTab = 'currencies'" :class="activeTab === 'currencies' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">
                    Currencies
                </button>
            </div>
        </div>

        <!-- Tab Content -->
        <form method="POST" action="{{ route('admin.settings.update') }}">
            <div class="p-8 space-y-6">
                @csrf

            <!-- General Tab -->
            <div x-show="activeTab === 'general'" class="space-y-4">
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Site Information</legend>
                    <div class="space-y-4">
                        <x-form-input useOld="false" name="settings[site_name]" label="Site Name" value="{{ $settings['site_name'] ?? 'Talksasa Cloud' }}" useOld="false" required />
                        <x-form-input useOld="false" name="settings[site_url]" label="Site URL" type="url" value="{{ $settings['site_url'] ?? 'https://talksasa.cloud' }}" useOld="false" required />
                        <x-form-input useOld="false" name="settings[site_email]" label="System Email" type="email" value="{{ $settings['site_email'] ?? '' }}" useOld="false" required />
                        <x-form-input useOld="false" name="settings[support_email]" label="Support Email" type="email" value="{{ $settings['support_email'] ?? '' }}" useOld="false" />
                    </div>
                </fieldset>

                <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Regional Settings</legend>
                    <div class="space-y-4">
                        <x-form-select useOld="false" name="settings[timezone]" label="Timezone" :options="['UTC' => 'UTC', 'Africa/Nairobi' => 'Africa/Nairobi', 'Africa/Johannesburg' => 'Africa/Johannesburg']" value="{{ $settings['timezone'] ?? 'UTC' }}" />
                        @php
                            $currencyOptions = $currencies->pluck('name', 'code')->toArray();
                        @endphp
                        <x-form-select useOld="false" name="settings[currency]" label="Default Currency" :options="$currencyOptions" value="{{ $settings['currency'] ?? 'KES' }}" />
                        @php
                            $selectedCurrency = $currencies->where('code', $settings['currency'] ?? 'KES')->first();
                        @endphp
                        <x-form-input useOld="false" name="settings[currency_symbol]" label="Currency Symbol" value="{{ $selectedCurrency->symbol ?? 'KES' }}" readonly />
                    </div>
                </fieldset>
            </div>

            <!-- Billing Tab -->
            <div x-show="activeTab === 'billing'" class="space-y-4">
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Company Information</legend>
                    <div class="space-y-4">
                        <x-form-input useOld="false" name="settings[billing_company]" label="Company Name" value="{{ $settings['billing_company'] ?? 'Talksasa Cloud Ltd' }}" />
                        <x-form-input useOld="false" name="settings[billing_address]" label="Address" value="{{ $settings['billing_address'] ?? '' }}" />
                        <x-form-input useOld="false" name="settings[billing_city]" label="City" value="{{ $settings['billing_city'] ?? 'Nairobi' }}" />
                        <x-form-input useOld="false" name="settings[billing_country]" label="Country" value="{{ $settings['billing_country'] ?? 'Kenya' }}" />
                        <x-form-input useOld="false" name="settings[billing_vat_number]" label="VAT/Tax Number" value="{{ $settings['billing_vat_number'] ?? '' }}" />
                    </div>
                </fieldset>

                <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Invoice Settings</legend>
                    <div class="space-y-4">
                        <x-form-input useOld="false" name="settings[invoice_prefix]" label="Invoice Number Prefix" value="{{ $settings['invoice_prefix'] ?? 'INV' }}" />
                        <x-form-input useOld="false" name="settings[invoice_due_days]" label="Invoice Due Days" type="number" value="{{ $settings['invoice_due_days'] ?? '30' }}" />
                        <x-form-input useOld="false" name="settings[grace_period_days]" label="Grace Period (days)" type="number" value="{{ $settings['grace_period_days'] ?? '7' }}" />
                    </div>
                </fieldset>
            </div>

            <!-- Tax Tab -->
            <div x-show="activeTab === 'tax'" class="space-y-4">
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Tax Configuration</legend>
                    <div class="space-y-4">
                        <div>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="settings[tax_enabled]" value="1" @checked(($settings['tax_enabled'] ?? '0') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Enable Tax Calculation</span>
                            </label>
                        </div>

                        <x-form-input useOld="false" name="settings[tax_rate]" label="Tax Rate (%)" type="number" step="0.01" value="{{ $settings['tax_rate'] ?? '16' }}" />
                        <x-form-input useOld="false" name="settings[tax_name]" label="Tax Name" value="{{ $settings['tax_name'] ?? 'VAT' }}" />

                        <div>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="settings[tax_inclusive]" value="1" @checked(($settings['tax_inclusive'] ?? '0') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Tax Inclusive (prices already include tax)</span>
                            </label>
                        </div>

                        <x-form-input useOld="false" name="settings[tax_number]" label="Tax Registration Number" value="{{ $settings['tax_number'] ?? '' }}" />
                    </div>
                </fieldset>
            </div>

            <!-- Payment Methods Tab -->
            <div x-show="activeTab === 'payment_methods'" class="space-y-2">
                <!-- M-Pesa Gateway -->
                <div x-data="{ expanded: false }" class="border border-slate-200 dark:border-slate-800 rounded-lg overflow-hidden">
                    <button @click="expanded = !expanded" type="button" class="w-full flex items-center justify-between p-4 bg-slate-50 dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 transition">
                        <div class="flex items-center gap-3">
                            <svg class="w-5 h-5 text-slate-600 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <div class="text-left">
                                <h3 class="font-semibold text-slate-900 dark:text-white">M-Pesa</h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Safaricom Mobile Money</p>
                            </div>
                        </div>
                        <svg :class="expanded ? 'rotate-180' : ''" class="w-5 h-5 text-slate-600 dark:text-slate-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                        </svg>
                    </button>

                    <div x-show="expanded" class="border-t border-slate-200 dark:border-slate-800 p-6 space-y-4">
                        <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-4">
                            @csrf
                            <fieldset>
                                <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Enable M-Pesa</legend>
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[mpesa_enabled]" value="1" @checked(($settings['mpesa_enabled'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                                    <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Enable M-Pesa payments</span>
                                </label>
                            </fieldset>

                            <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                                <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Shortcode & Passkey</legend>
                                <div class="space-y-4">
                                    <x-form-input useOld="false" name="settings[mpesa_shortcode]" label="Shortcode" value="{{ $settings['mpesa_shortcode'] ?? '' }}" />
                                    <x-form-input useOld="false" name="settings[mpesa_passkey]" label="Passkey" type="password" value="{{ $settings['mpesa_passkey'] ?? '' }}" />
                                </div>
                            </fieldset>

                            <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                                <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Daraja API Credentials</legend>
                                <div class="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-lg p-3 mb-4">
                                    <p class="text-sm text-blue-700 dark:text-blue-300">
                                        <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                        Obtain from <a href="https://developer.safaricom.co.ke" target="_blank" class="underline">developer.safaricom.co.ke</a>
                                    </p>
                                </div>
                                <div class="space-y-4">
                                    <x-form-select useOld="false" name="settings[mpesa_environment]" label="Environment" :options="['sandbox' => 'Sandbox (Testing)', 'production' => 'Production']" value="{{ $settings['mpesa_environment'] ?? 'sandbox' }}" />
                                    <x-form-input useOld="false" name="settings[mpesa_consumer_key]" label="Consumer Key" value="{{ $settings['mpesa_consumer_key'] ?? '' }}" />
                                    <x-form-input useOld="false" name="settings[mpesa_consumer_secret]" label="Consumer Secret" type="password" value="{{ $settings['mpesa_consumer_secret'] ?? '' }}" />
                                </div>
                            </fieldset>

                            <div class="pt-4 border-t border-slate-200 dark:border-slate-800 flex gap-3">
                                <button type="button" @click="expanded = false" class="px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                                    Cancel
                                </button>
                                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                                    Save M-Pesa Settings
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Stripe Gateway -->
                <div x-data="{ expanded: false }" class="border border-slate-200 dark:border-slate-800 rounded-lg overflow-hidden">
                    <button @click="expanded = !expanded" type="button" class="w-full flex items-center justify-between p-4 bg-slate-50 dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 transition">
                        <div class="flex items-center gap-3">
                            <svg class="w-5 h-5 text-slate-600 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10l6-3 6 3 6-3v11a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>
                            </svg>
                            <div class="text-left">
                                <h3 class="font-semibold text-slate-900 dark:text-white">Stripe</h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Card Payments</p>
                            </div>
                        </div>
                        <svg :class="expanded ? 'rotate-180' : ''" class="w-5 h-5 text-slate-600 dark:text-slate-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                        </svg>
                    </button>

                    <div x-show="expanded" class="border-t border-slate-200 dark:border-slate-800 p-6 space-y-4">
                        <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-4">
                            @csrf
                            <fieldset>
                                <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Enable Stripe</legend>
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[card_enabled]" value="1" @checked(($settings['card_enabled'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                                    <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Enable card payments via Stripe</span>
                                </label>
                            </fieldset>

                            <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                                <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-3">API Key</legend>
                                <div class="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-lg p-3 mb-4">
                                    <p class="text-sm text-blue-700 dark:text-blue-300">
                                        <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                        Obtain from <a href="https://dashboard.stripe.com" target="_blank" class="underline">dashboard.stripe.com</a>
                                    </p>
                                </div>
                                <x-form-input useOld="false" name="settings[stripe_key]" label="Publishable Key" value="{{ $settings['stripe_key'] ?? '' }}" placeholder="pk_live_..." />
                            </fieldset>

                            <div class="pt-4 border-t border-slate-200 dark:border-slate-800 flex gap-3">
                                <button type="button" @click="expanded = false" class="px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                                    Cancel
                                </button>
                                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                                    Save Stripe Settings
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Manual Payment Gateway -->
                <div x-data="{ expanded: false }" class="border border-slate-200 dark:border-slate-800 rounded-lg overflow-hidden">
                    <button @click="expanded = !expanded" type="button" class="w-full flex items-center justify-between p-4 bg-slate-50 dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 transition">
                        <div class="flex items-center gap-3">
                            <svg class="w-5 h-5 text-slate-600 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            <div class="text-left">
                                <h3 class="font-semibold text-slate-900 dark:text-white">Manual Payment</h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Bank Transfer with Admin Review</p>
                            </div>
                        </div>
                        <svg :class="expanded ? 'rotate-180' : ''" class="w-5 h-5 text-slate-600 dark:text-slate-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                        </svg>
                    </button>

                    <div x-show="expanded" class="border-t border-slate-200 dark:border-slate-800 p-6 space-y-4">
                        <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-4">
                            @csrf
                            <fieldset>
                                <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Enable Manual Payment</legend>
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[manual_enabled]" value="1" @checked(($settings['manual_enabled'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                                    <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Enable manual payment recording</span>
                                </label>
                            </fieldset>

                            <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                                <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Bank Account Details</legend>
                                <div class="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-lg p-3 mb-4">
                                    <p class="text-sm text-blue-900 dark:text-blue-300">
                                        <strong>ℹ️ How this works:</strong> Customers will see these bank details during checkout. After they submit payment proof, an admin reviews and approves it.
                                    </p>
                                </div>
                                <div class="space-y-4">
                                    <x-form-input useOld="false" name="settings[manual_bank_name]" label="Bank Name" value="{{ $settings['manual_bank_name'] ?? '' }}" placeholder="e.g., Equity Bank Kenya" />
                                    <x-form-input useOld="false" name="settings[manual_account_name]" label="Account Name" value="{{ $settings['manual_account_name'] ?? '' }}" placeholder="e.g., Talksasa Cloud Limited" />
                                    <x-form-input useOld="false" name="settings[manual_account_number]" label="Account Number" value="{{ $settings['manual_account_number'] ?? '' }}" placeholder="e.g., 0123456789" />
                                    <x-form-input useOld="false" name="settings[manual_bank_branch]" label="Branch (Optional)" value="{{ $settings['manual_bank_branch'] ?? '' }}" placeholder="e.g., Westlands Branch" />
                                    <x-form-input useOld="false" name="settings[manual_bank_swift]" label="SWIFT/BIC Code (Optional)" value="{{ $settings['manual_bank_swift'] ?? '' }}" placeholder="e.g., EQBLKENA" />
                                </div>
                            </fieldset>

                            <!-- Preview -->
                            <div class="pt-4 border-t border-slate-200 dark:border-slate-800">
                                <p class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Preview (How customers see this):</p>
                                <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-700 rounded-lg space-y-2 text-sm">
                                    <div>
                                        <p class="text-emerald-700 dark:text-emerald-400 text-xs font-semibold uppercase">Bank</p>
                                        <p class="text-emerald-900 dark:text-emerald-200 font-bold">{{ $settings['manual_bank_name'] ?: '(Not set)' }}</p>
                                    </div>
                                    <div>
                                        <p class="text-emerald-700 dark:text-emerald-400 text-xs font-semibold uppercase">Account</p>
                                        <p class="text-emerald-900 dark:text-emerald-200 font-bold">{{ $settings['manual_account_name'] ?: '(Not set)' }}</p>
                                    </div>
                                    <div>
                                        <p class="text-emerald-700 dark:text-emerald-400 text-xs font-semibold uppercase">Number</p>
                                        <p class="text-emerald-900 dark:text-emerald-200 font-bold font-mono">{{ $settings['manual_account_number'] ?: '(Not set)' }}</p>
                                    </div>
                                </div>
                            </div>

                            <div class="pt-4 border-t border-slate-200 dark:border-slate-800 flex gap-3">
                                <button type="button" @click="expanded = false" class="px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                                    Cancel
                                </button>
                                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                                    Save Manual Payment Settings
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Provisioning Tab -->
            <div x-show="activeTab === 'provisioning'" class="space-y-4">
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Provisioning Settings</legend>
                    <div class="space-y-4">
                        <x-form-select useOld="false" name="settings[provisioning_mode]" label="Provisioning Mode" :options="['manual' => 'Manual', 'automatic' => 'Automatic']" value="{{ $settings['provisioning_mode'] ?? 'manual' }}" />

                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[auto_provision]" value="1" @checked(($settings['auto_provision'] ?? '0') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Auto-Provision Immediately After Payment</span>
                        </label>

                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[suspend_on_overdue]" value="1" @checked(($settings['suspend_on_overdue'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Suspend Services on Overdue Payment</span>
                        </label>

                        <x-form-input useOld="false" name="settings[terminate_after_days]" label="Terminate Service After (days)" type="number" value="{{ $settings['terminate_after_days'] ?? '60' }}" />
                    </div>
                </fieldset>

                <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">DirectAdmin Configuration</legend>
                    <div class="space-y-4">
                        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-3 mb-4">
                            <p class="text-sm text-blue-700 dark:text-blue-300">
                                <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                Configure DirectAdmin API credentials for automatic hosting account provisioning.
                            </p>
                        </div>
                        <x-form-input useOld="false" name="settings[directadmin_api_url]" label="DirectAdmin API URL" placeholder="https://your-directadmin-server.com:2222" value="{{ $settings['directadmin_api_url'] ?? '' }}" />
                        <x-form-input useOld="false" name="settings[directadmin_api_user]" label="DirectAdmin Admin Username" placeholder="admin" value="{{ $settings['directadmin_api_user'] ?? 'admin' }}" />
                        <x-form-input useOld="false" name="settings[directadmin_api_password]" label="DirectAdmin Admin Password" type="password" value="{{ $settings['directadmin_api_password'] ?? '' }}" />
                        <x-form-input useOld="false" name="settings[directadmin_default_package]" label="Default Hosting Package" placeholder="default" value="{{ $settings['directadmin_default_package'] ?? 'default' }}" />
                        <p class="text-xs text-slate-600 dark:text-slate-400">Leave URL blank to disable DirectAdmin provisioning. Services will be marked active without automatic account creation.</p>
                    </div>
                </fieldset>
            </div>

            <!-- Branding Tab -->
            <div x-show="activeTab === 'branding'" class="space-y-6">
                <!-- Logo Section -->
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Logo</legend>
                    <div class="space-y-4">
                        <div x-data="brandingUpload('{{ $settings['logo_url'] ?? '' }}')">
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Logo Image</label>
                            <div class="flex gap-4">
                                <!-- Upload Area -->
                                <div class="flex-1">
                                    <div class="relative">
                                        <input
                                            type="file"
                                            id="logoUpload"
                                            @change="upload($event, 'logo')"
                                            accept="image/*"
                                            class="hidden"
                                        />
                                        <label
                                            for="logoUpload"
                                            class="flex items-center justify-center w-full px-6 py-8 border-2 border-dashed border-slate-300 dark:border-slate-600 rounded-lg hover:border-blue-500 dark:hover:border-blue-500 hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer transition-all"
                                        >
                                            <div class="text-center">
                                                <svg class="w-8 h-8 mx-auto text-slate-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                </svg>
                                                <p class="text-sm text-slate-600 dark:text-slate-400">
                                                    <span x-show="!uploading">Click to upload or drag and drop</span>
                                                    <span x-show="uploading">Uploading...</span>
                                                </p>
                                                <p class="text-xs text-slate-500 dark:text-slate-500 mt-1">PNG, JPG, GIF up to 5MB</p>
                                            </div>
                                        </label>
                                    </div>
                                </div>

                                <!-- Preview -->
                                <div class="flex flex-col items-center justify-center">
                                    <div class="w-24 h-24 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center border border-slate-200 dark:border-slate-700">
                                        <img
                                            x-show="preview"
                                            :src="preview"
                                            alt="Logo preview"
                                            onerror="this.style.display='none'"
                                            class="w-full h-full object-contain p-2 rounded"
                                        />
                                        <svg
                                            x-show="!preview"
                                            class="w-12 h-12 text-slate-300"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                    </div>
                                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-2">Preview</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </fieldset>

                <!-- Favicon Section -->
                <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Favicon</legend>
                    <div class="space-y-4">
                        <div x-data="brandingUpload('{{ $settings['favicon_url'] ?? '' }}')">
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Favicon Image</label>
                            <div class="flex gap-4">
                                <!-- Upload Area -->
                                <div class="flex-1">
                                    <div class="relative">
                                        <input
                                            type="file"
                                            id="faviconUpload"
                                            @change="upload($event, 'favicon')"
                                            accept="image/*"
                                            class="hidden"
                                        />
                                        <label
                                            for="faviconUpload"
                                            class="flex items-center justify-center w-full px-6 py-8 border-2 border-dashed border-slate-300 dark:border-slate-600 rounded-lg hover:border-blue-500 dark:hover:border-blue-500 hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer transition-all"
                                        >
                                            <div class="text-center">
                                                <svg class="w-8 h-8 mx-auto text-slate-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                </svg>
                                                <p class="text-sm text-slate-600 dark:text-slate-400">
                                                    <span x-show="!uploading">Click to upload or drag and drop</span>
                                                    <span x-show="uploading">Uploading...</span>
                                                </p>
                                                <p class="text-xs text-slate-500 dark:text-slate-500 mt-1">PNG, JPG, ICO up to 5MB (32x32 recommended)</p>
                                            </div>
                                        </label>
                                    </div>
                                </div>

                                <!-- Preview -->
                                <div class="flex flex-col items-center justify-center">
                                    <div class="w-16 h-16 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center border border-slate-200 dark:border-slate-700">
                                        <img
                                            x-show="preview"
                                            :src="preview"
                                            alt="Favicon preview"
                                            onerror="this.style.display='none'"
                                            class="w-full h-full object-contain p-1 rounded"
                                        />
                                        <svg
                                            x-show="!preview"
                                            class="w-8 h-8 text-slate-300"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                    </div>
                                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-2">Preview</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </fieldset>

                <!-- Other Branding Options -->
                <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Other Options</legend>
                    <div class="space-y-4">
                        <x-form-input useOld="false" name="settings[primary_color]" label="Primary Color" type="color" value="{{ $settings['primary_color'] ?? '#2563eb' }}" />
                        <x-form-input useOld="false" name="settings[company_name]" label="Company Name" value="{{ $settings['company_name'] ?? 'Talksasa Cloud' }}" />
                        <x-form-input useOld="false" name="settings[footer_text]" label="Footer Text" value="{{ $settings['footer_text'] ?? '© 2026 Talksasa Cloud. All rights reserved.' }}" />
                    </div>
                </fieldset>
            </div>

            <!-- Email Tab -->
            <div x-show="activeTab === 'email'" class="space-y-4">
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">SMTP Configuration</legend>
                    <div class="space-y-4">
                        <x-form-input useOld="false" name="settings[smtp_host]" label="SMTP Host" value="{{ $settings['smtp_host'] ?? 'smtp.mailtrap.io' }}" />
                        <x-form-input useOld="false" name="settings[smtp_port]" label="SMTP Port" type="number" value="{{ $settings['smtp_port'] ?? '587' }}" />
                        <x-form-input useOld="false" name="settings[smtp_user]" label="SMTP Username" value="{{ $settings['smtp_user'] ?? '' }}" />
                        <x-form-input useOld="false" name="settings[smtp_password]" label="SMTP Password" type="password" value="{{ $settings['smtp_password'] ?? '' }}" />
                    </div>
                </fieldset>

                <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Email Settings</legend>
                    <div class="space-y-4">
                        <x-form-input useOld="false" name="settings[mail_from_name]" label="From Name" value="{{ $settings['mail_from_name'] ?? 'Talksasa Cloud' }}" />
                        <x-form-input useOld="false" name="settings[mail_from_address]" label="From Address" type="email" value="{{ $settings['mail_from_address'] ?? 'noreply@talksasa.cloud' }}" />
                    </div>
                </fieldset>

                <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Test SMTP Configuration</legend>
                    <div x-data="{ testEmail: '', testing: false }" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Test Email Address</label>
                            <input type="email" x-model="testEmail" placeholder="your@email.com" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                            <p class="text-xs text-slate-600 dark:text-slate-400 mt-2">Enter an email address to send a test email and verify your SMTP settings are working correctly.</p>
                        </div>
                        <button type="button" @click="testSmtpEmail()" :disabled="!testEmail || testing" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-400 text-white rounded-lg font-medium transition text-sm">
                            <span x-show="!testing">Send Test Email</span>
                            <span x-show="testing">Testing...</span>
                        </button>
                    </div>
                </fieldset>
            </div>

            <!-- Notifications Tab -->
            <div x-show="activeTab === 'notifications'" class="space-y-4">
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">General Notifications</legend>
                    <div class="space-y-3">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[notify_new_order]" value="1" @checked(($settings['notify_new_order'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Notify on New Orders</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[notify_ticket]" value="1" @checked(($settings['notify_ticket'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Notify on New Support Tickets</span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Invoice Notifications</legend>
                    <div class="space-y-3">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[notify_invoice_generated]" value="1" @checked(($settings['notify_invoice_generated'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Notify When Invoice Generated</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[notify_invoice_reminder]" value="1" @checked(($settings['notify_invoice_reminder'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Notify With Invoice Payment Reminders</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[notify_invoice_overdue]" value="1" @checked(($settings['notify_invoice_overdue'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Notify When Invoice Becomes Overdue</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[notify_payment]" value="1" @checked(($settings['notify_payment'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Notify on Payment Received</span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Service Notifications</legend>
                    <div class="space-y-3">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[notify_service_activated]" value="1" @checked(($settings['notify_service_activated'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Notify When Service is Activated</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[notify_service_suspend]" value="1" @checked(($settings['notify_service_suspend'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Notify on Service Suspension</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[notify_service_terminated]" value="1" @checked(($settings['notify_service_terminated'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Notify When Service is Terminated</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[notify_domain_expiry]" value="1" @checked(($settings['notify_domain_expiry'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Notify on Domain Expiry Warnings</span>
                        </label>
                    </div>
                </fieldset>
            </div>

            <!-- Cron Tab -->
            <div x-show="activeTab === 'cron'" class="space-y-4">
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-6">
                    <p class="text-sm text-blue-700 dark:text-blue-300">
                        <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Configure automated cron job scheduling and monitoring retention settings.
                    </p>
                </div>

                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Scheduling</legend>
                    <div class="space-y-4">
                        <x-form-select useOld="false" name="settings[cron_timezone]" label="Cron Job Timezone" :options="['UTC' => 'UTC', 'Africa/Nairobi' => 'Africa/Nairobi', 'Africa/Johannesburg' => 'Africa/Johannesburg', 'Africa/Lagos' => 'Africa/Lagos', 'Africa/Cairo' => 'Africa/Cairo']" value="{{ $settings['cron_timezone'] ?? 'Africa/Nairobi' }}" />
                        <p class="text-xs text-slate-600 dark:text-slate-400">Timezone used for scheduling all cron jobs. Update this to match your local timezone.</p>
                    </div>
                </fieldset>

                <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Execution & Retention</legend>
                    <div class="space-y-4">
                        <x-form-input useOld="false" name="settings[max_execution_time]" label="Maximum Execution Time (seconds)" type="number" value="{{ $settings['max_execution_time'] ?? '120' }}" min="10" max="3600" />
                        <p class="text-xs text-slate-600 dark:text-slate-400">Maximum time allowed for a single cron job to execute before timeout.</p>

                        <x-form-input useOld="false" name="settings[cron_retention_days]" label="Log Retention Period (days)" type="number" value="{{ $settings['cron_retention_days'] ?? '30' }}" min="1" max="365" />
                        <p class="text-xs text-slate-600 dark:text-slate-400">Number of days to keep cron job logs and monitoring data. Older records are automatically deleted.</p>
                    </div>
                </fieldset>

                <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Server Cron Configuration</legend>
                    <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4 mb-6">
                        <p class="text-sm text-amber-700 dark:text-amber-300 flex items-start gap-2">
                            <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4v2m0 0v2M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span><strong>CRITICAL:</strong> Add the cron command below to your server's crontab to enable automatic job execution. Without this, cron jobs will not run!</span>
                        </p>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-3">Cron Command (Copy & Add to Crontab)</label>
                            <div class="relative">
                                <input type="text" id="cronCommand" readonly value="{{ php_uname('a') }}" class="w-full px-4 py-3 bg-slate-950 text-emerald-400 font-mono text-sm rounded-lg border border-slate-700 focus:border-blue-500 focus:outline-none" />
                                <button type="button" onclick="copyCronCommand()" class="absolute right-3 top-1/2 -translate-y-1/2 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded transition-colors">
                                    Copy
                                </button>
                            </div>
                            <p class="text-xs text-slate-600 dark:text-slate-400 mt-2">Command generated from your server environment. This triggers the Laravel scheduler every minute.</p>
                        </div>

                        <div class="bg-slate-100 dark:bg-slate-800 rounded-lg p-4 space-y-3">
                            <h4 class="text-sm font-semibold text-slate-900 dark:text-white">Server Information</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                                <div>
                                    <p class="text-slate-600 dark:text-slate-400">Project Path</p>
                                    <p class="text-slate-900 dark:text-white font-mono text-xs break-all">{{ base_path() }}</p>
                                </div>
                                <div>
                                    <p class="text-slate-600 dark:text-slate-400">PHP Executable</p>
                                    <p class="text-slate-900 dark:text-white font-mono text-xs break-all">{{ PHP_BINARY }}</p>
                                </div>
                                <div>
                                    <p class="text-slate-600 dark:text-slate-400">Log Directory</p>
                                    <p class="text-slate-900 dark:text-white font-mono text-xs break-all">{{ storage_path('logs') }}</p>
                                </div>
                                <div>
                                    <p class="text-slate-600 dark:text-slate-400">Artisan Path</p>
                                    <p class="text-slate-900 dark:text-white font-mono text-xs break-all">{{ base_path('artisan') }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                            <h4 class="text-sm font-semibold text-blue-900 dark:text-blue-200 mb-3">How to Add to Crontab</h4>
                            <ol class="text-sm text-blue-800 dark:text-blue-300 space-y-2 list-decimal list-inside mb-4">
                                <li>SSH into your server</li>
                                <li>Run: <code class="bg-blue-900/50 px-2 py-1 rounded text-xs font-mono">crontab -e</code></li>
                                <li>Paste the command above at the end of the file</li>
                                <li>Save and exit (Ctrl+X for nano, :wq for vim)</li>
                                <li>Verify: <code class="bg-blue-900/50 px-2 py-1 rounded text-xs font-mono">crontab -l</code> should show your entry</li>
                                <li>Check logs: <code class="bg-blue-900/50 px-2 py-1 rounded text-xs font-mono">tail -f {{ storage_path('logs/schedule.log') }}</code></li>
                            </ol>
                            <div class="pt-3 border-t border-blue-200 dark:border-blue-900">
                                <p class="text-sm text-blue-800 dark:text-blue-300 mb-2">💡 <strong>Tip:</strong> You can also view this information via command line:</p>
                                <code class="bg-blue-900/50 px-2 py-1 rounded text-xs font-mono text-blue-100">php artisan cron:show-setup</code>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-slate-200 dark:border-slate-700 space-y-4">
                            <!-- Scheduler Status -->
                            <div>
                                <h4 class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Scheduler Status</h4>
                                @php
                                    $latestLog = \App\Models\CronJobLog::latest('started_at')->first();
                                    $isActive = $latestLog && $latestLog->started_at->diffInMinutes(now()) <= 5;
                                @endphp

                                <div class="flex items-center justify-between p-3 rounded-lg bg-slate-100 dark:bg-slate-800">
                                    <div class="flex items-center gap-3">
                                        <div class="flex items-center justify-center w-8 h-8 rounded-full {{ $isActive ? 'bg-green-100 dark:bg-green-900' : 'bg-yellow-100 dark:bg-yellow-900' }}">
                                            <div class="w-3 h-3 rounded-full {{ $isActive ? 'bg-green-500' : 'bg-yellow-500' }} {{ $isActive ? 'animate-pulse' : '' }}"></div>
                                        </div>
                                        <div>
                                            <p class="text-sm font-medium text-slate-900 dark:text-white">
                                                {{ $isActive ? '🟢 Active' : '🟡 Inactive' }}
                                            </p>
                                            <p class="text-xs text-slate-600 dark:text-slate-400">
                                                @if ($latestLog)
                                                    Last activity {{ $latestLog->started_at->diffForHumans() }}
                                                @else
                                                    No activity recorded yet
                                                @endif
                                            </p>
                                        </div>
                                    </div>
                                    @if ($isActive)
                                        <span class="inline-flex items-center gap-1.5 px-2 py-1 bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300 rounded text-xs font-medium">
                                            ✓ Running
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 px-2 py-1 bg-yellow-100 dark:bg-yellow-900 text-yellow-700 dark:text-yellow-300 rounded text-xs font-medium">
                                            ⚠ Setup Required
                                        </span>
                                    @endif
                                </div>

                                @if (!$isActive)
                                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-2">
                                        ⚠️ Scheduler is not running. Add the cron command above to your server's crontab.
                                    </p>
                                @else
                                    <p class="text-xs text-green-700 dark:text-green-300 mt-2">
                                        ✅ Cron scheduler is active and processing jobs regularly.
                                    </p>
                                @endif
                            </div>

                            <!-- Dashboard Link -->
                            <a href="{{ route('admin.cron.index') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                Go to Cron Jobs Dashboard
                            </a>
                        </div>
                    </div>
                </fieldset>
            </div>

            <!-- SMS Tab -->
            <div x-show="activeTab === 'sms'" class="space-y-4">
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-6">
                    <p class="text-sm text-blue-700 dark:text-blue-300">
                        <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                        </svg>
                        Configure SMS notifications using the Talksasa Bulk SMS API.
                    </p>
                </div>

                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">API Configuration</legend>
                    <div class="space-y-4">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" name="settings[sms_enabled]" value="1" @checked(($settings['sms_enabled'] ?? '0') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Enable SMS Notifications</span>
                        </label>
                        <x-form-input useOld="false" name="settings[sms_api_token]" label="API Token" type="password" value="{{ $settings['sms_api_token'] ?? '' }}" placeholder="Bearer token from Talksasa" />
                        <x-form-input useOld="false" name="settings[sms_sender_id]" label="Sender ID" value="{{ $settings['sms_sender_id'] ?? 'TalksasaCloud' }}" maxlength="11" />
                        <p class="text-xs text-slate-600 dark:text-slate-400">Sender ID must be 11 characters or less. This will appear as the SMS sender name.</p>
                    </div>
                </fieldset>

                <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Test SMS Configuration</legend>
                    <div x-data="{ testPhone: '', testingSms: false }" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Test Phone Number</label>
                            <input type="text" x-model="testPhone" placeholder="+254700000000" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                            <p class="text-xs text-slate-600 dark:text-slate-400 mt-2">Enter a phone number to send a test SMS and verify your API configuration is working correctly.</p>
                        </div>
                        <button type="button" @click="testSmtpSms(testPhone)" :disabled="!testPhone || testingSms" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-400 text-white rounded-lg font-medium transition text-sm">
                            <span x-show="!testingSms">Send Test SMS</span>
                            <span x-show="testingSms">Sending...</span>
                        </button>
                    </div>
                </fieldset>
            </div>

            <!-- Currencies Tab -->
            <div x-show="activeTab === 'currencies'" class="space-y-4">
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-6">
                    <p class="text-sm text-blue-700 dark:text-blue-300">
                        <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Manage currencies and exchange rates. Base currency is Kenya Shilling (KES).
                    </p>
                </div>

                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Currency Management</legend>

                    <!-- Quick Stats -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                        <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-4">
                            <p class="text-xs text-slate-600 dark:text-slate-400 mb-1">Base Currency</p>
                            <p class="text-xl font-bold text-slate-900 dark:text-white">KES</p>
                        </div>
                        <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-4">
                            <p class="text-xs text-slate-600 dark:text-slate-400 mb-1">Active Currencies</p>
                            <p class="text-xl font-bold text-slate-900 dark:text-white">{{ $currencies->where('is_active', true)->count() }}</p>
                        </div>
                        <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-4">
                            <p class="text-xs text-slate-600 dark:text-slate-400 mb-1">Last Updated</p>
                            @php
                                $lastUpdated = $currencies->max('rate_updated_at');
                            @endphp
                            <p class="text-sm font-mono text-slate-900 dark:text-white">
                                @if ($lastUpdated)
                                    {{ \Carbon\Carbon::parse($lastUpdated)->format('M d H:i') }}
                                @else
                                    Never
                                @endif
                            </p>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div x-data="{ refreshingRates: false }" class="flex flex-wrap gap-3 mb-6">
                        <button type="button" @click="refreshExchangeRates()" :disabled="refreshingRates" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-400 text-white rounded-lg font-medium transition text-sm">
                            <span x-show="!refreshingRates">🔄 Refresh Exchange Rates</span>
                            <span x-show="refreshingRates">Refreshing...</span>
                        </button>
                        <a href="{{ route('admin.currencies.index') }}" class="px-4 py-2 bg-slate-600 hover:bg-slate-700 text-white rounded-lg font-medium transition text-sm inline-flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                            </svg>
                            Full Currency Manager
                        </a>
                    </div>

                    <!-- Currencies Table -->
                    <div class="overflow-x-auto border border-slate-200 dark:border-slate-700 rounded-lg">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-100 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold text-slate-900 dark:text-white">Code</th>
                                    <th class="px-4 py-3 text-left font-semibold text-slate-900 dark:text-white">Name</th>
                                    <th class="px-4 py-3 text-left font-semibold text-slate-900 dark:text-white">Symbol</th>
                                    <th class="px-4 py-3 text-left font-semibold text-slate-900 dark:text-white">Exchange Rate</th>
                                    <th class="px-4 py-3 text-left font-semibold text-slate-900 dark:text-white">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                                @forelse ($currencies->sortByDesc('is_active')->sortBy('code') as $currency)
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition">
                                        <td class="px-4 py-3 font-mono font-semibold text-slate-900 dark:text-white">
                                            {{ $currency->code }}
                                            @if ($currency->code === 'KES')
                                                <span class="ml-2 px-2 py-0.5 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 text-xs rounded">Base</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-slate-700 dark:text-slate-300">{{ $currency->name }}</td>
                                        <td class="px-4 py-3 text-slate-700 dark:text-slate-300 font-semibold">{{ $currency->symbol }}</td>
                                        <td class="px-4 py-3 text-slate-700 dark:text-slate-300 font-mono text-xs">{{ number_format($currency->exchange_rate, 6) }}</td>
                                        <td class="px-4 py-3">
                                            @if ($currency->is_active)
                                                <span class="inline-flex items-center gap-1.5 px-2 py-1 bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300 text-xs rounded-full font-medium">
                                                    ✓ Active
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1.5 px-2 py-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-xs rounded-full font-medium">
                                                    Inactive
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-6 text-center text-slate-600 dark:text-slate-400">
                                            No currencies configured. <a href="{{ route('admin.currencies.index') }}" class="text-blue-600 hover:underline font-medium">Manage currencies</a>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </fieldset>
            </div>

            <!-- Save Button -->
            <div class="mt-8 pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                <p class="text-sm text-slate-600 dark:text-slate-400" id="save-status">
                    All changes will be saved when you click Save Settings
                </p>
                <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    Save Settings
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // Handle form submission feedback
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('form');
        if (form) {
            form.addEventListener('submit', function(e) {
                const submitBtn = form.querySelector('button[type="submit"]');
                const statusMsg = document.getElementById('save-status');

                submitBtn.disabled = true;
                submitBtn.innerHTML = '<svg class="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg> Saving...';
                statusMsg.textContent = 'Saving settings...';
            });
        }
    });

    // Generate dynamic cron command
    document.addEventListener('DOMContentLoaded', function() {
        const projectPath = '{{ base_path() }}';
        const phpBinary = '{{ PHP_BINARY }}';
        const logsPath = '{{ storage_path('logs/schedule.log') }}';

        // Build the cron command
        const cronCommand = `* * * * * ${phpBinary} ${projectPath}/artisan schedule:run >> ${logsPath} 2>&1`;

        const cronCommandInput = document.getElementById('cronCommand');
        if (cronCommandInput) {
            cronCommandInput.value = cronCommand;
        }
    });

    function testSmtpEmail() {
        // This function is called from Alpine.js x-data in the SMTP section
        // It would trigger an SMTP test via AJAX if needed
        // For now, the UI updates via Alpine reactivity
    }

    async function testSmtpSms(phone) {
        try {
            const response = await fetch('{{ route('admin.settings.test-sms') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ phone })
            });

            const data = await response.json();
            if (data.success) {
                alert('SMS sent successfully to ' + phone);
            } else {
                alert('Failed to send SMS: ' + (data.message || 'Unknown error'));
            }
        } catch (error) {
            console.error('SMS test error:', error);
            alert('Error sending test SMS: ' + error.message);
        }
    }

    async function refreshExchangeRates() {
        try {
            const response = await fetch('{{ route('admin.currencies.refresh') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            });

            const data = await response.json();
            if (data.success) {
                alert('Exchange rates refreshed successfully');
                // Optionally reload page to show updated rates
                setTimeout(() => location.reload(), 1000);
            } else {
                alert('Failed to refresh rates: ' + (data.message || 'Unknown error'));
            }
        } catch (error) {
            console.error('Rate refresh error:', error);
            alert('Error refreshing exchange rates: ' + error.message);
        }
    }

    function copyCronCommand() {
        const cronCommand = document.getElementById('cronCommand');

        // Copy to clipboard
        cronCommand.select();
        cronCommand.setSelectionRange(0, 99999);

        try {
            document.execCommand('copy');

            // Show success feedback
            const button = event.target;
            const originalText = button.textContent;
            button.textContent = 'Copied!';
            button.classList.remove('bg-blue-600', 'hover:bg-blue-700');
            button.classList.add('bg-green-600');

            setTimeout(() => {
                button.textContent = originalText;
                button.classList.remove('bg-green-600');
                button.classList.add('bg-blue-600', 'hover:bg-blue-700');
            }, 2000);
        } catch (err) {
            alert('Failed to copy command. Please copy manually.');
        }
    }

    // Alpine.js component for branding uploads
    function brandingUpload(initialPreview) {
        return {
            uploading: false,
            preview: initialPreview,

            upload(event, type) {
                const file = event.target.files[0];
                if (!file) return;

                const formData = new FormData();
                formData.append('file', file);
                formData.append('type', type);
                formData.append('_token', document.querySelector('meta[name="csrf-token"]').content);

                this.uploading = true;

                fetch('{{ route('admin.settings.upload-file') }}', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        this.preview = data.url;
                        alert(data.message);
                        event.target.value = '';
                    } else {
                        alert('Upload failed: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Upload failed. Please try again.');
                })
                .finally(() => {
                    this.uploading = false;
                });
            }
        };
    }
</script>
@endpush

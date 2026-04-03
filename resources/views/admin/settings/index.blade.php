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
            </div>
        </div>

        <!-- Tab Content -->
        <form method="POST" action="{{ route('admin.settings.update') }}" class="p-8 space-y-6">
            @csrf

            <!-- General Tab -->
            <div x-show="activeTab === 'general'" class="space-y-4">
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Site Information</legend>
                    <div class="space-y-4">
                        <x-form-input name="settings[site_name]" label="Site Name" value="{{ $settings['site_name'] ?? 'Talksasa Cloud' }}" required />
                        <x-form-input name="settings[site_url]" label="Site URL" type="url" value="{{ $settings['site_url'] ?? 'https://talksasa.cloud' }}" required />
                        <x-form-input name="settings[site_email]" label="System Email" type="email" value="{{ $settings['site_email'] ?? '' }}" required />
                        <x-form-input name="settings[support_email]" label="Support Email" type="email" value="{{ $settings['support_email'] ?? '' }}" />
                    </div>
                </fieldset>

                <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Regional Settings</legend>
                    <div class="space-y-4">
                        <x-form-select name="settings[timezone]" label="Timezone" :options="['UTC' => 'UTC', 'Africa/Nairobi' => 'Africa/Nairobi', 'Africa/Johannesburg' => 'Africa/Johannesburg']" value="{{ $settings['timezone'] ?? 'UTC' }}" />
                        <x-form-select name="settings[currency]" label="Default Currency" :options="['KES' => 'KES', 'USD' => 'USD', 'EUR' => 'EUR']" value="{{ $settings['currency'] ?? 'KES' }}" />
                        <x-form-input name="settings[currency_symbol]" label="Currency Symbol" value="{{ $settings['currency_symbol'] ?? 'Ksh' }}" />
                    </div>
                </fieldset>
            </div>

            <!-- Billing Tab -->
            <div x-show="activeTab === 'billing'" class="space-y-4">
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Company Information</legend>
                    <div class="space-y-4">
                        <x-form-input name="settings[billing_company]" label="Company Name" value="{{ $settings['billing_company'] ?? 'Talksasa Cloud Ltd' }}" />
                        <x-form-input name="settings[billing_address]" label="Address" value="{{ $settings['billing_address'] ?? '' }}" />
                        <x-form-input name="settings[billing_city]" label="City" value="{{ $settings['billing_city'] ?? 'Nairobi' }}" />
                        <x-form-input name="settings[billing_country]" label="Country" value="{{ $settings['billing_country'] ?? 'Kenya' }}" />
                        <x-form-input name="settings[billing_vat_number]" label="VAT/Tax Number" value="{{ $settings['billing_vat_number'] ?? '' }}" />
                    </div>
                </fieldset>

                <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Invoice Settings</legend>
                    <div class="space-y-4">
                        <x-form-input name="settings[invoice_prefix]" label="Invoice Number Prefix" value="{{ $settings['invoice_prefix'] ?? 'INV' }}" />
                        <x-form-input name="settings[invoice_due_days]" label="Invoice Due Days" type="number" value="{{ $settings['invoice_due_days'] ?? '30' }}" />
                        <x-form-input name="settings[grace_period_days]" label="Grace Period (days)" type="number" value="{{ $settings['grace_period_days'] ?? '7' }}" />
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

                        <x-form-input name="settings[tax_rate]" label="Tax Rate (%)" type="number" step="0.01" value="{{ $settings['tax_rate'] ?? '16' }}" />
                        <x-form-input name="settings[tax_name]" label="Tax Name" value="{{ $settings['tax_name'] ?? 'VAT' }}" />

                        <div>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="settings[tax_inclusive]" value="1" @checked(($settings['tax_inclusive'] ?? '0') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Tax Inclusive (prices already include tax)</span>
                            </label>
                        </div>

                        <x-form-input name="settings[tax_number]" label="Tax Registration Number" value="{{ $settings['tax_number'] ?? '' }}" />
                    </div>
                </fieldset>
            </div>

            <!-- Payment Methods Tab -->
            <div x-show="activeTab === 'payment_methods'" class="space-y-4">
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Enabled Payment Methods</legend>
                    <div class="space-y-3">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[mpesa_enabled]" value="1" @checked(($settings['mpesa_enabled'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">M-Pesa</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[card_enabled]" value="1" @checked(($settings['card_enabled'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Card Payments (Stripe)</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[bank_transfer_enabled]" value="1" @checked(($settings['bank_transfer_enabled'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Bank Transfer</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[manual_enabled]" value="1" @checked(($settings['manual_enabled'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Manual Payment Recording</span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">M-Pesa Credentials</legend>
                    <div class="space-y-4">
                        <x-form-input name="settings[mpesa_shortcode]" label="Shortcode" value="{{ $settings['mpesa_shortcode'] ?? '' }}" />
                        <x-form-input name="settings[mpesa_passkey]" label="Passkey" type="password" value="{{ $settings['mpesa_passkey'] ?? '' }}" />
                    </div>
                </fieldset>

                <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Bank Transfer Details</legend>
                    <div class="space-y-4">
                        <x-form-input name="settings[bank_name]" label="Bank Name" value="{{ $settings['bank_name'] ?? '' }}" />
                        <x-form-input name="settings[bank_account_name]" label="Account Name" value="{{ $settings['bank_account_name'] ?? '' }}" />
                        <x-form-input name="settings[bank_account_number]" label="Account Number" value="{{ $settings['bank_account_number'] ?? '' }}" />
                    </div>
                </fieldset>
            </div>

            <!-- Provisioning Tab -->
            <div x-show="activeTab === 'provisioning'" class="space-y-4">
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Provisioning Settings</legend>
                    <div class="space-y-4">
                        <x-form-select name="settings[provisioning_mode]" label="Provisioning Mode" :options="['manual' => 'Manual', 'automatic' => 'Automatic']" value="{{ $settings['provisioning_mode'] ?? 'manual' }}" />

                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[auto_provision]" value="1" @checked(($settings['auto_provision'] ?? '0') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Auto-Provision Immediately After Payment</span>
                        </label>

                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[suspend_on_overdue]" value="1" @checked(($settings['suspend_on_overdue'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Suspend Services on Overdue Payment</span>
                        </label>

                        <x-form-input name="settings[terminate_after_days]" label="Terminate Service After (days)" type="number" value="{{ $settings['terminate_after_days'] ?? '60' }}" />
                    </div>
                </fieldset>
            </div>

            <!-- Branding Tab -->
            <div x-show="activeTab === 'branding'" class="space-y-4">
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Branding & Appearance</legend>
                    <div class="space-y-4">
                        <x-form-input name="settings[logo_url]" label="Logo URL" type="url" value="{{ $settings['logo_url'] ?? '' }}" />
                        <x-form-input name="settings[favicon_url]" label="Favicon URL" type="url" value="{{ $settings['favicon_url'] ?? '' }}" />
                        <x-form-input name="settings[primary_color]" label="Primary Color" type="color" value="{{ $settings['primary_color'] ?? '#2563eb' }}" />
                        <x-form-input name="settings[company_name]" label="Company Name" value="{{ $settings['company_name'] ?? 'Talksasa Cloud' }}" />
                        <x-form-input name="settings[footer_text]" label="Footer Text" value="{{ $settings['footer_text'] ?? '© 2026 Talksasa Cloud. All rights reserved.' }}" />
                    </div>
                </fieldset>
            </div>

            <!-- Email Tab -->
            <div x-show="activeTab === 'email'" class="space-y-4">
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">SMTP Configuration</legend>
                    <div class="space-y-4">
                        <x-form-input name="settings[smtp_host]" label="SMTP Host" value="{{ $settings['smtp_host'] ?? 'smtp.mailtrap.io' }}" />
                        <x-form-input name="settings[smtp_port]" label="SMTP Port" type="number" value="{{ $settings['smtp_port'] ?? '587' }}" />
                        <x-form-input name="settings[smtp_user]" label="SMTP Username" value="{{ $settings['smtp_user'] ?? '' }}" />
                        <x-form-input name="settings[smtp_password]" label="SMTP Password" type="password" value="{{ $settings['smtp_password'] ?? '' }}" />
                    </div>
                </fieldset>

                <fieldset class="pt-4 border-t border-slate-200 dark:border-slate-800">
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Email Settings</legend>
                    <div class="space-y-4">
                        <x-form-input name="settings[mail_from_name]" label="From Name" value="{{ $settings['mail_from_name'] ?? 'Talksasa Cloud' }}" />
                        <x-form-input name="settings[mail_from_address]" label="From Address" type="email" value="{{ $settings['mail_from_address'] ?? 'noreply@talksasa.cloud' }}" />
                    </div>
                </fieldset>
            </div>

            <!-- Notifications Tab -->
            <div x-show="activeTab === 'notifications'" class="space-y-4">
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Notification Preferences</legend>
                    <div class="space-y-3">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[notify_new_order]" value="1" @checked(($settings['notify_new_order'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Notify on New Orders</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[notify_payment]" value="1" @checked(($settings['notify_payment'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Notify on Payment Received</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[notify_service_suspend]" value="1" @checked(($settings['notify_service_suspend'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Notify on Service Suspension</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="settings[notify_ticket]" value="1" @checked(($settings['notify_ticket'] ?? '1') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Notify on New Support Tickets</span>
                        </label>
                    </div>
                </fieldset>
            </div>

            <!-- Save Button -->
            <div class="border-t border-slate-200 dark:border-slate-800 pt-6">
                <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors">
                    <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    Save Settings
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

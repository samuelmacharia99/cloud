@extends('layouts.admin')

@section('title', 'Settings')

@section('breadcrumb')
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Settings</p>
@endsection

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Platform Settings</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Configure system-wide settings and preferences.</p>
    </div>

    <div x-data="{ activeTab: 'general' }" class="space-y-6">

        <!-- Tab Navigation -->
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-x-auto">
            <div class="flex gap-1 px-6 min-w-max">
                <button @click="activeTab = 'general'" :class="activeTab === 'general' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">General</button>
                <button @click="activeTab = 'billing'" :class="activeTab === 'billing' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">Billing</button>
                <button @click="activeTab = 'tax'" :class="activeTab === 'tax' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">Tax</button>
                <button @click="activeTab = 'payment'" :class="activeTab === 'payment' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">Payment</button>
                <button @click="activeTab = 'provisioning'" :class="activeTab === 'provisioning' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">Provisioning</button>
                <button @click="activeTab = 'branding'" :class="activeTab === 'branding' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">Branding</button>
                <button @click="activeTab = 'email'" :class="activeTab === 'email' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">Email</button>
                <button @click="activeTab = 'notifications'" :class="activeTab === 'notifications' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">Notifications</button>
                <button @click="activeTab = 'cron'" :class="activeTab === 'cron' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">Cron</button>
                <button @click="activeTab = 'sms'" :class="activeTab === 'sms' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">SMS</button>
                <button @click="activeTab = 'currencies'" :class="activeTab === 'currencies' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">Currencies</button>
            </div>
        </div>

        <!-- Tab: General -->
        <div x-show="activeTab === 'general'">
            <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="submitForm($el)">
                @csrf
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Site Information</legend>
                    <div class="space-y-4">
                        <x-form-input useOld="false" name="settings[site_name]" label="Site Name" value="{{ $settings['site_name'] ?? 'Talksasa Cloud' }}" required />
                        <x-form-input useOld="false" name="settings[site_url]" label="Site URL" type="url" value="{{ $settings['site_url'] ?? 'https://talksasa.cloud' }}" required />
                        <x-form-input useOld="false" name="settings[site_email]" label="System Email" type="email" value="{{ $settings['site_email'] ?? '' }}" required />
                    </div>
                </fieldset>
                <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                    <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                    <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Save General
                    </button>
                </div>
            </form>
        </div>

        <!-- Tab: Billing -->
        <div x-show="activeTab === 'billing'">
            <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="submitForm($el)">
                @csrf
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Billing Settings</legend>
                    <div class="space-y-4">
                        <x-form-input useOld="false" name="settings[company_name]" label="Company Name" value="{{ $settings['company_name'] ?? '' }}" />
                        <x-form-input useOld="false" name="settings[company_address]" label="Company Address" value="{{ $settings['company_address'] ?? '' }}" />
                        <x-form-input useOld="false" name="settings[invoice_prefix]" label="Invoice Prefix" value="{{ $settings['invoice_prefix'] ?? 'INV' }}" />
                        <x-form-input useOld="false" name="settings[invoice_number_start]" label="Next Invoice Number" type="number" value="{{ $settings['invoice_number_start'] ?? '1000' }}" />
                    </div>
                </fieldset>
                <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                    <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                    <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Save Billing
                    </button>
                </div>
            </form>
        </div>

        <!-- Tab: Tax -->
        <div x-show="activeTab === 'tax'">
            <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="submitForm($el)">
                @csrf
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Tax Configuration</legend>
                    <div class="space-y-4">
                        <div>
                            <input type="hidden" name="settings[tax_enabled]" value="0">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="settings[tax_enabled]" value="1" @checked(($settings['tax_enabled'] ?? '0') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Enable Tax</span>
                            </label>
                        </div>
                        <x-form-input useOld="false" name="settings[tax_rate]" label="Tax Rate (%)" type="number" step="0.01" value="{{ $settings['tax_rate'] ?? '0' }}" />
                        <x-form-input useOld="false" name="settings[tax_id]" label="Tax ID" value="{{ $settings['tax_id'] ?? '' }}" />
                    </div>
                </fieldset>
                <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                    <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                    <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Save Tax
                    </button>
                </div>
            </form>
        </div>

        <!-- Tab: Payment Methods -->
        <div x-show="activeTab === 'payment'">
            <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="submitForm($el)">
                @csrf
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Payment Methods</legend>
                    <div class="space-y-6">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-3">M-Pesa</h3>
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">API Passkey</label>
                                    @if($settings['mpesa_passkey'] ?? false)
                                        <p class="text-sm text-green-600 dark:text-green-400 mb-2">✓ Configured</p>
                                    @endif
                                    <input type="password" name="settings[mpesa_passkey]" placeholder="M-Pesa passkey" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 bg-white text-slate-900 placeholder-slate-400 focus:border-blue-500 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-800 dark:text-white" />
                                </div>
                                <x-form-input useOld="false" name="settings[mpesa_business_code]" label="Business Code" value="{{ $settings['mpesa_business_code'] ?? '' }}" />
                            </div>
                        </div>
                        <div class="pt-6 border-t border-slate-200 dark:border-slate-800">
                            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-3">Stripe</h3>
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">API Key</label>
                                    @if($settings['stripe_key'] ?? false)
                                        <p class="text-sm text-green-600 dark:text-green-400 mb-2">✓ Configured</p>
                                    @endif
                                    <input type="password" name="settings[stripe_key]" placeholder="Stripe API key" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 bg-white text-slate-900 placeholder-slate-400 focus:border-blue-500 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-800 dark:text-white" />
                                </div>
                            </div>
                        </div>
                    </div>
                </fieldset>
                <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                    <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                    <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Save Payment Methods
                    </button>
                </div>
            </form>
        </div>

        <!-- Tab: Provisioning -->
        <div x-show="activeTab === 'provisioning'">
            <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="submitForm($el)">
                @csrf
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Provisioning & DirectAdmin</legend>
                    <div class="space-y-6">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-3">Auto-Provisioning</h3>
                            <div class="space-y-4">
                                <div>
                                    <input type="hidden" name="settings[auto_provisioning_enabled]" value="0">
                                    <label class="flex items-center gap-2">
                                        <input type="checkbox" name="settings[auto_provisioning_enabled]" value="1" @checked(($settings['auto_provisioning_enabled'] ?? '0') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                                        <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Enable Auto-Provisioning</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="pt-6 border-t border-slate-200 dark:border-slate-800">
                            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-3">DirectAdmin</h3>
                            <div class="space-y-4">
                                <x-form-input useOld="false" name="settings[directadmin_url]" label="DirectAdmin URL" type="url" value="{{ $settings['directadmin_url'] ?? '' }}" />
                                <x-form-input useOld="false" name="settings[directadmin_user]" label="DirectAdmin User" value="{{ $settings['directadmin_user'] ?? '' }}" />
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">DirectAdmin Password</label>
                                    @if($settings['directadmin_api_password'] ?? false)
                                        <p class="text-sm text-green-600 dark:text-green-400 mb-2">✓ Configured</p>
                                    @endif
                                    <input type="password" name="settings[directadmin_api_password]" placeholder="DirectAdmin password" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 bg-white text-slate-900 placeholder-slate-400 focus:border-blue-500 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-800 dark:text-white" />
                                </div>
                            </div>
                        </div>
                    </div>
                </fieldset>
                <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                    <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                    <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Save Provisioning
                    </button>
                </div>
            </form>
        </div>

        <!-- Tab: Branding -->
        <div x-show="activeTab === 'branding'">
            <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="submitForm($el)">
                @csrf
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Branding Settings</legend>
                    <div class="space-y-4">
                        <x-form-input useOld="false" name="settings[app_name]" label="Application Name" value="{{ $settings['app_name'] ?? 'Talksasa Cloud' }}" />
                        <x-form-input useOld="false" name="settings[primary_color]" label="Primary Color" type="color" value="{{ $settings['primary_color'] ?? '#2563eb' }}" />
                        <x-form-input useOld="false" name="settings[footer_text]" label="Footer Text" value="{{ $settings['footer_text'] ?? 'Talksasa Cloud &copy; 2024' }}" />
                    </div>
                </fieldset>
                <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                    <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                    <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Save Branding
                    </button>
                </div>
            </form>
        </div>

        <!-- Tab: Email -->
        <div x-show="activeTab === 'email'">
            <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="submitForm($el)">
                @csrf
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Email Configuration</legend>
                    <div class="space-y-4">
                        <x-form-input useOld="false" name="settings[smtp_host]" label="SMTP Host" value="{{ $settings['smtp_host'] ?? '' }}" />
                        <x-form-input useOld="false" name="settings[smtp_port]" label="SMTP Port" type="number" value="{{ $settings['smtp_port'] ?? '587' }}" />
                        <x-form-input useOld="false" name="settings[smtp_user]" label="SMTP User" value="{{ $settings['smtp_user'] ?? '' }}" />
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">SMTP Password</label>
                            @if($settings['smtp_password'] ?? false)
                                <p class="text-sm text-green-600 dark:text-green-400 mb-2">✓ Configured</p>
                            @endif
                            <input type="password" name="settings[smtp_password]" placeholder="SMTP password" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 bg-white text-slate-900 placeholder-slate-400 focus:border-blue-500 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-800 dark:text-white" />
                        </div>
                    </div>
                </fieldset>
                <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                    <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                    <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Save Email
                    </button>
                </div>
            </form>
        </div>

        <!-- Tab: Notifications -->
        <div x-show="activeTab === 'notifications'">
            <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="submitForm($el)">
                @csrf
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Notification Settings</legend>
                    <div class="space-y-3">
                        <div>
                            <input type="hidden" name="settings[notify_new_order]" value="0">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="settings[notify_new_order]" value="1" @checked(($settings['notify_new_order'] ?? '0') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">New Order</span>
                            </label>
                        </div>
                        <div>
                            <input type="hidden" name="settings[notify_payment]" value="0">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="settings[notify_payment]" value="1" @checked(($settings['notify_payment'] ?? '0') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Payment Received</span>
                            </label>
                        </div>
                        <div>
                            <input type="hidden" name="settings[notify_service_suspend]" value="0">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="settings[notify_service_suspend]" value="1" @checked(($settings['notify_service_suspend'] ?? '0') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Service Suspended</span>
                            </label>
                        </div>
                        <div>
                            <input type="hidden" name="settings[notify_ticket]" value="0">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="settings[notify_ticket]" value="1" @checked(($settings['notify_ticket'] ?? '0') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Support Ticket</span>
                            </label>
                        </div>
                        <div>
                            <input type="hidden" name="settings[notify_invoice_generated]" value="0">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="settings[notify_invoice_generated]" value="1" @checked(($settings['notify_invoice_generated'] ?? '0') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Invoice Generated</span>
                            </label>
                        </div>
                        <div>
                            <input type="hidden" name="settings[notify_invoice_reminder]" value="0">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="settings[notify_invoice_reminder]" value="1" @checked(($settings['notify_invoice_reminder'] ?? '0') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Invoice Reminder</span>
                            </label>
                        </div>
                        <div>
                            <input type="hidden" name="settings[notify_invoice_overdue]" value="0">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="settings[notify_invoice_overdue]" value="1" @checked(($settings['notify_invoice_overdue'] ?? '0') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Invoice Overdue</span>
                            </label>
                        </div>
                        <div>
                            <input type="hidden" name="settings[notify_service_activated]" value="0">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="settings[notify_service_activated]" value="1" @checked(($settings['notify_service_activated'] ?? '0') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Service Activated</span>
                            </label>
                        </div>
                        <div>
                            <input type="hidden" name="settings[notify_service_terminated]" value="0">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="settings[notify_service_terminated]" value="1" @checked(($settings['notify_service_terminated'] ?? '0') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Service Terminated</span>
                            </label>
                        </div>
                        <div>
                            <input type="hidden" name="settings[notify_domain_expiry]" value="0">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="settings[notify_domain_expiry]" value="1" @checked(($settings['notify_domain_expiry'] ?? '0') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Domain Expiry</span>
                            </label>
                        </div>
                    </div>
                </fieldset>
                <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                    <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                    <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Save Notifications
                    </button>
                </div>
            </form>
        </div>

        <!-- Tab: Cron -->
        <div x-show="activeTab === 'cron'">
            <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="submitForm($el)">
                @csrf
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Cron & Scheduler</legend>
                    <div class="space-y-4">
                        <x-form-input useOld="false" name="settings[cron_timezone]" label="Timezone" value="{{ $settings['cron_timezone'] ?? 'UTC' }}" />
                        <x-form-input useOld="false" name="settings[invoice_retention_days]" label="Invoice Retention (days)" type="number" value="{{ $settings['invoice_retention_days'] ?? '2555' }}" />
                        <x-form-input useOld="false" name="settings[log_retention_days]" label="Log Retention (days)" type="number" value="{{ $settings['log_retention_days'] ?? '90' }}" />
                    </div>
                </fieldset>
                <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                    <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                    <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Save Cron Settings
                    </button>
                </div>
            </form>
        </div>

        <!-- Tab: SMS -->
        <div x-show="activeTab === 'sms'">
            <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="submitForm($el)">
                @csrf
                <fieldset>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white mb-4">SMS Configuration</legend>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">API Token</label>
                            @if($settings['sms_api_token'] ?? false)
                                <p class="text-sm text-green-600 dark:text-green-400 mb-2">✓ Configured</p>
                            @endif
                            <input type="password" name="settings[sms_api_token]" placeholder="Bearer token" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 bg-white text-slate-900 placeholder-slate-400 focus:border-blue-500 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-800 dark:text-white" />
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">@if($settings['sms_api_token'] ?? false)Token configured. Leave blank to keep.@else Enter your SMS API token@endif</p>
                        </div>
                        <x-form-input useOld="false" name="settings[sms_sender_id]" label="Sender ID" value="{{ $settings['sms_sender_id'] ?? 'TalksasaCloud' }}" maxlength="11" />
                        <p class="text-xs text-slate-600 dark:text-slate-400">Max 11 characters</p>
                        <div>
                            <input type="hidden" name="settings[sms_enabled]" value="0">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="settings[sms_enabled]" value="1" @checked(($settings['sms_enabled'] ?? '0') == '1') class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Enable SMS</span>
                            </label>
                        </div>
                    </div>
                </fieldset>
                <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                    <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                    <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Save SMS
                    </button>
                </div>
            </form>
        </div>

        <!-- Tab: Currencies -->
        <div x-show="activeTab === 'currencies'">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-2">Currency Management</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Manage global exchange rates and supported currencies.</p>
                    <button type="button" onclick="refreshExchangeRates()" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Refresh Exchange Rates
                    </button>
                </div>
                <div id="currency-status" class="text-sm text-slate-600 dark:text-slate-400" style="display:none;"></div>
            </div>
        </div>

    </div>
</div>
@endsection

@push('scripts')
<script>
    async function submitForm(form) {
        const statusEl = form.querySelector('.save-status');
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalHTML = submitBtn.innerHTML;

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<svg class="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg> Saving...';

        try {
            const formData = new FormData(form);
            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (response.ok && data.success) {
                statusEl.textContent = '✅ ' + data.message;
                statusEl.className = 'text-sm text-green-600 dark:text-green-400 save-status';
                statusEl.style.display = 'block';
                setTimeout(() => { statusEl.style.display = 'none'; }, 3000);
            } else {
                statusEl.textContent = '❌ ' + (data.message || 'Error saving');
                statusEl.className = 'text-sm text-red-600 dark:text-red-400 save-status';
                statusEl.style.display = 'block';
            }
        } catch (error) {
            statusEl.textContent = '❌ Error: ' + error.message;
            statusEl.className = 'text-sm text-red-600 dark:text-red-400 save-status';
            statusEl.style.display = 'block';
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHTML;
        }
    }

    function refreshExchangeRates() {
        const statusEl = document.getElementById('currency-status');
        const btn = event.target;
        const originalHTML = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = '<svg class="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg> Refreshing...';

        fetch('{{ route("admin.settings.refresh-currencies") }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                statusEl.textContent = '✅ Exchange rates refreshed successfully';
                statusEl.className = 'text-green-600 dark:text-green-400';
                statusEl.style.display = 'block';
                setTimeout(() => { statusEl.style.display = 'none'; }, 3000);
            } else {
                statusEl.textContent = '❌ ' + (data.message || 'Failed to refresh');
                statusEl.className = 'text-red-600 dark:text-red-400';
                statusEl.style.display = 'block';
            }
        })
        .catch(err => {
            statusEl.textContent = '❌ Error: ' + err.message;
            statusEl.className = 'text-red-600 dark:text-red-400';
            statusEl.style.display = 'block';
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        });
    }
</script>
@endpush

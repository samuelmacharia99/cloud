@extends('layouts.admin')

@section('title', 'Settings')

@section('content')
<div class="bg-slate-50 dark:bg-slate-950 py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Settings</h1>
        </div>

        <div x-data="{ activeTab: 'general' }" class="space-y-6">
            <!-- Tab Navigation -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800">
                <div class="flex gap-1 px-6 overflow-x-auto">
                    <button @click="activeTab = 'general'" :class="activeTab === 'general' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm whitespace-nowrap">General</button>
                    <button @click="activeTab = 'billing'" :class="activeTab === 'billing' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm whitespace-nowrap">Billing</button>
                    <button @click="activeTab = 'tax'" :class="activeTab === 'tax' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm whitespace-nowrap">Tax</button>
                    <button @click="activeTab = 'payment_methods'" :class="activeTab === 'payment_methods' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm whitespace-nowrap">Payment Methods</button>
                    <button @click="activeTab = 'provisioning'" :class="activeTab === 'provisioning' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm whitespace-nowrap">Provisioning</button>
                    <button @click="activeTab = 'branding'" :class="activeTab === 'branding' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm whitespace-nowrap">Branding</button>
                    <button @click="activeTab = 'email'" :class="activeTab === 'email' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm whitespace-nowrap">Email</button>
                    <button @click="activeTab = 'notifications'" :class="activeTab === 'notifications' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm whitespace-nowrap">Notifications</button>
                    <button @click="activeTab = 'cron'" :class="activeTab === 'cron' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm whitespace-nowrap">Cron</button>
                    <button @click="activeTab = 'sms'" :class="activeTab === 'sms' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm whitespace-nowrap">SMS</button>
                </div>
            </div>

            <!-- Tab: General -->
            <div x-show="activeTab === 'general'" class="space-y-6">
                <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="window.submitForm($el)">
                    @csrf

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Site Information</legend>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Site Name</label>
                                <input type="text" name="settings[site_name]" value="{{ $settings['site_name'] ?? 'Talksasa Cloud' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Site URL</label>
                                <input type="url" name="settings[site_url]" value="{{ $settings['site_url'] ?? '' }}" placeholder="https://talksasa.cloud" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Site Email</label>
                                <input type="email" name="settings[site_email]" value="{{ $settings['site_email'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Support Email</label>
                                <input type="email" name="settings[support_email]" value="{{ $settings['support_email'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Localization</legend>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Timezone</label>
                                <input type="text" name="settings[timezone]" value="{{ $settings['timezone'] ?? 'UTC' }}" placeholder="UTC" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Date Format</label>
                                <input type="text" name="settings[date_format]" value="{{ $settings['date_format'] ?? 'Y-m-d' }}" placeholder="Y-m-d" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Currency</label>
                                <select name="settings[currency]" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                                    @foreach($currencies as $curr)
                                        <option value="{{ $curr->code }}" @selected(($settings['currency'] ?? 'USD') === $curr->code)>{{ $curr->code }} - {{ $curr->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Currency Symbol</label>
                                <input type="text" name="settings[currency_symbol]" value="{{ $settings['currency_symbol'] ?? '$' }}" placeholder="$" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                        </div>
                    </fieldset>

                    <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                        <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                        <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Save General
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tab: Billing -->
            <div x-show="activeTab === 'billing'" class="space-y-6">
                <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="window.submitForm($el)">
                    @csrf

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Company Billing Details</legend>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Company Name</label>
                                <input type="text" name="settings[billing_company]" value="{{ $settings['billing_company'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Address</label>
                                <input type="text" name="settings[billing_address]" value="{{ $settings['billing_address'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">City</label>
                                <input type="text" name="settings[billing_city]" value="{{ $settings['billing_city'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Country</label>
                                <input type="text" name="settings[billing_country]" value="{{ $settings['billing_country'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">VAT Number</label>
                                <input type="text" name="settings[billing_vat_number]" value="{{ $settings['billing_vat_number'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Invoice Settings</legend>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Invoice Prefix</label>
                                <input type="text" name="settings[invoice_prefix]" value="{{ $settings['invoice_prefix'] ?? 'INV' }}" placeholder="INV" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Days Until Due</label>
                                <input type="number" name="settings[invoice_due_days]" value="{{ $settings['invoice_due_days'] ?? '30' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Grace Period (Days)</label>
                                <input type="number" name="settings[grace_period_days]" value="{{ $settings['grace_period_days'] ?? '5' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                        </div>
                    </fieldset>

                    <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                        <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                        <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Save Billing
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tab: Tax -->
            <div x-show="activeTab === 'tax'" class="space-y-6">
                <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="window.submitForm($el)">
                    @csrf

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Tax Configuration</legend>
                        <div class="space-y-4">
                            <div>
                                <input type="hidden" name="settings[tax_enabled]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[tax_enabled]" value="1" @checked(($settings['tax_enabled'] ?? '0') == '1')" class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Enable Tax Calculation</span>
                                </label>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Tax Name</label>
                                <input type="text" name="settings[tax_name]" value="{{ $settings['tax_name'] ?? 'VAT' }}" placeholder="VAT" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Tax Rate (%)</label>
                                <input type="number" name="settings[tax_rate]" step="0.01" value="{{ $settings['tax_rate'] ?? '0' }}" placeholder="0.00" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <input type="hidden" name="settings[tax_inclusive]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[tax_inclusive]" value="1" @checked(($settings['tax_inclusive'] ?? '0') == '1')" class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Tax Inclusive (included in displayed price)</span>
                                </label>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Tax Number</label>
                                <input type="text" name="settings[tax_number]" value="{{ $settings['tax_number'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                        </div>
                    </fieldset>

                    <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                        <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                        <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Save Tax
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tab: Payment Methods -->
            <div x-show="activeTab === 'payment_methods'" class="space-y-6">
                <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="window.submitForm($el)">
                    @csrf

                    <!-- M-Pesa Section -->
                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">M-Pesa</legend>
                        <div class="space-y-4">
                            <div>
                                <input type="hidden" name="settings[mpesa_enabled]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[mpesa_enabled]" value="1" @checked(($settings['mpesa_enabled'] ?? '0') == '1')" class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Enable M-Pesa</span>
                                </label>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Short Code</label>
                                <input type="text" name="settings[mpesa_shortcode]" value="{{ $settings['mpesa_shortcode'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Pass Key</label>
                                @if($settings['mpesa_passkey'] ?? false)
                                    <p class="text-sm text-green-600 dark:text-green-400 mb-2">✓ Configured</p>
                                @endif
                                <input type="password" name="settings[mpesa_passkey]" placeholder="Leave blank to keep existing" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">
                                    @if($settings['mpesa_passkey'] ?? false)
                                        A pass key is configured. Leave blank to keep it.
                                    @else
                                        Enter your M-Pesa pass key
                                    @endif
                                </p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Consumer Key</label>
                                <input type="text" name="settings[mpesa_consumer_key]" value="{{ $settings['mpesa_consumer_key'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Consumer Secret</label>
                                <input type="text" name="settings[mpesa_consumer_secret]" value="{{ $settings['mpesa_consumer_secret'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Environment</label>
                                <select name="settings[mpesa_environment]" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                                    <option value="sandbox" @selected(($settings['mpesa_environment'] ?? 'sandbox') === 'sandbox')>Sandbox</option>
                                    <option value="production" @selected(($settings['mpesa_environment'] ?? 'sandbox') === 'production')>Production</option>
                                </select>
                            </div>
                        </div>
                    </fieldset>

                    <!-- Card/Stripe Section -->
                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Card Payments (Stripe)</legend>
                        <div class="space-y-4">
                            <div>
                                <input type="hidden" name="settings[card_enabled]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[card_enabled]" value="1" @checked(($settings['card_enabled'] ?? '0') == '1')" class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Enable Card Payments</span>
                                </label>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Stripe API Key</label>
                                @if($settings['stripe_key'] ?? false)
                                    <p class="text-sm text-green-600 dark:text-green-400 mb-2">✓ Configured</p>
                                @endif
                                <input type="password" name="settings[stripe_key]" placeholder="Leave blank to keep existing" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">
                                    @if($settings['stripe_key'] ?? false)
                                        A key is configured. Leave blank to keep it.
                                    @else
                                        Enter your Stripe API key
                                    @endif
                                </p>
                            </div>
                        </div>
                    </fieldset>

                    <!-- Bank Transfer Section -->
                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Bank Transfer</legend>
                        <div class="space-y-4">
                            <div>
                                <input type="hidden" name="settings[bank_transfer_enabled]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[bank_transfer_enabled]" value="1" @checked(($settings['bank_transfer_enabled'] ?? '0') == '1')" class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Enable Bank Transfer</span>
                                </label>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Bank Name</label>
                                <input type="text" name="settings[bank_name]" value="{{ $settings['bank_name'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Account Name</label>
                                <input type="text" name="settings[bank_account_name]" value="{{ $settings['bank_account_name'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Account Number</label>
                                <input type="text" name="settings[bank_account_number]" value="{{ $settings['bank_account_number'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Branch</label>
                                <input type="text" name="settings[bank_branch]" value="{{ $settings['bank_branch'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">SWIFT Code</label>
                                <input type="text" name="settings[bank_swift_code]" value="{{ $settings['bank_swift_code'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                        </div>
                    </fieldset>

                    <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                        <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                        <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Save Payment Methods
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tab: Provisioning -->
            <div x-show="activeTab === 'provisioning'" class="space-y-6">
                <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="window.submitForm($el)">
                    @csrf

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Service Provisioning</legend>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Provisioning Mode</label>
                                <select name="settings[provisioning_mode]" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                                    <option value="manual" @selected(($settings['provisioning_mode'] ?? 'manual') === 'manual')>Manual</option>
                                    <option value="automatic" @selected(($settings['provisioning_mode'] ?? 'manual') === 'automatic')>Automatic</option>
                                </select>
                            </div>

                            <div>
                                <input type="hidden" name="settings[auto_provision]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[auto_provision]" value="1" @checked(($settings['auto_provision'] ?? '0') == '1')" class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Auto-provision on payment</span>
                                </label>
                            </div>

                            <div>
                                <input type="hidden" name="settings[suspend_on_overdue]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[suspend_on_overdue]" value="1" @checked(($settings['suspend_on_overdue'] ?? '0') == '1')" class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Suspend service on overdue invoice</span>
                                </label>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Days Until Automatic Termination</label>
                                <input type="number" name="settings[terminate_after_days]" value="{{ $settings['terminate_after_days'] ?? '30' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">DirectAdmin API</legend>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">API URL</label>
                                <input type="url" name="settings[directadmin_api_url]" value="{{ $settings['directadmin_api_url'] ?? '' }}" placeholder="https://host.com:2222/api" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">API User</label>
                                <input type="text" name="settings[directadmin_api_user]" value="{{ $settings['directadmin_api_user'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">API Password</label>
                                @if($settings['directadmin_api_password'] ?? false)
                                    <p class="text-sm text-green-600 dark:text-green-400 mb-2">✓ Configured</p>
                                @endif
                                <input type="password" name="settings[directadmin_api_password]" placeholder="Leave blank to keep existing" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">
                                    @if($settings['directadmin_api_password'] ?? false)
                                        A password is configured. Leave blank to keep it.
                                    @else
                                        Enter your DirectAdmin API password
                                    @endif
                                </p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Default Package</label>
                                <input type="text" name="settings[directadmin_default_package]" value="{{ $settings['directadmin_default_package'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                        </div>
                    </fieldset>

                    <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                        <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                        <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Save Provisioning
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tab: Branding -->
            <div x-show="activeTab === 'branding'" class="space-y-6">
                <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="window.submitForm($el)">
                    @csrf

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Branding</legend>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Company Name</label>
                                <input type="text" name="settings[company_name]" value="{{ $settings['company_name'] ?? 'Talksasa Cloud' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Primary Color</label>
                                <input type="color" name="settings[primary_color]" value="{{ $settings['primary_color'] ?? '#2563eb' }}" class="block w-full h-10 rounded-lg border border-slate-300 dark:border-slate-600 cursor-pointer" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Footer Text</label>
                                <textarea name="settings[footer_text]" rows="3" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">{{ $settings['footer_text'] ?? '' }}</textarea>
                            </div>

                            @if($settings['logo_url'] ?? false)
                                <div>
                                    <p class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Current Logo</p>
                                    <img src="{{ $settings['logo_url'] }}" alt="Logo" class="h-12 object-contain" />
                                </div>
                            @endif

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Upload Logo</label>
                                <input type="file" id="logo_upload" accept="image/*" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Max 5MB, PNG/JPG recommended</p>
                            </div>

                            @if($settings['favicon_url'] ?? false)
                                <div>
                                    <p class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Current Favicon</p>
                                    <img src="{{ $settings['favicon_url'] }}" alt="Favicon" class="w-8 h-8" />
                                </div>
                            @endif

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Upload Favicon</label>
                                <input type="file" id="favicon_upload" accept="image/*" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Max 5MB, PNG recommended</p>
                            </div>
                        </div>
                    </fieldset>

                    <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                        <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                        <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Save Branding
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tab: Email -->
            <div x-show="activeTab === 'email'" class="space-y-6">
                <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="window.submitForm($el)">
                    @csrf

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">SMTP Configuration</legend>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">SMTP Host</label>
                                <input type="text" name="settings[smtp_host]" value="{{ $settings['smtp_host'] ?? '' }}" placeholder="smtp.gmail.com" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">SMTP Port</label>
                                <input type="number" name="settings[smtp_port]" value="{{ $settings['smtp_port'] ?? '587' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">SMTP Username</label>
                                <input type="text" name="settings[smtp_user]" value="{{ $settings['smtp_user'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">SMTP Password</label>
                                @if($settings['smtp_password'] ?? false)
                                    <p class="text-sm text-green-600 dark:text-green-400 mb-2">✓ Configured</p>
                                @endif
                                <input type="password" name="settings[smtp_password]" placeholder="Leave blank to keep existing" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">
                                    @if($settings['smtp_password'] ?? false)
                                        A password is configured. Leave blank to keep it.
                                    @else
                                        Enter your SMTP password
                                    @endif
                                </p>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Email Sender Details</legend>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">From Name</label>
                                <input type="text" name="settings[mail_from_name]" value="{{ $settings['mail_from_name'] ?? 'Talksasa Cloud' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">From Address</label>
                                <input type="email" name="settings[mail_from_address]" value="{{ $settings['mail_from_address'] ?? 'noreply@talksasa.cloud' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Test Email</legend>
                        <div class="space-y-4">
                            <input type="email" id="test_email" placeholder="recipient@example.com" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            <button type="button" @click="window.sendTestEmail()" class="px-4 py-2 bg-slate-600 hover:bg-slate-700 text-white font-medium rounded-lg transition-colors">
                                Send Test Email
                            </button>
                        </div>
                    </fieldset>

                    <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                        <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                        <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Save Email
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tab: Notifications -->
            <div x-show="activeTab === 'notifications'" class="space-y-6">
                <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="window.submitForm($el)">
                    @csrf

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Notification Settings</legend>
                        <div class="space-y-3">
                            <div>
                                <input type="hidden" name="settings[notify_new_order]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_new_order]" value="1" @checked(($settings['notify_new_order'] ?? '0') == '1')" class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">New Order</span>
                                </label>
                            </div>

                            <div>
                                <input type="hidden" name="settings[notify_payment]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_payment]" value="1" @checked(($settings['notify_payment'] ?? '0') == '1')" class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Payment Received</span>
                                </label>
                            </div>

                            <div>
                                <input type="hidden" name="settings[notify_service_suspend]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_service_suspend]" value="1" @checked(($settings['notify_service_suspend'] ?? '0') == '1')" class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Service Suspended</span>
                                </label>
                            </div>

                            <div>
                                <input type="hidden" name="settings[notify_ticket]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_ticket]" value="1" @checked(($settings['notify_ticket'] ?? '0') == '1')" class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">New Ticket</span>
                                </label>
                            </div>

                            <div>
                                <input type="hidden" name="settings[notify_invoice_generated]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_invoice_generated]" value="1" @checked(($settings['notify_invoice_generated'] ?? '0') == '1')" class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Invoice Generated</span>
                                </label>
                            </div>

                            <div>
                                <input type="hidden" name="settings[notify_invoice_reminder]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_invoice_reminder]" value="1" @checked(($settings['notify_invoice_reminder'] ?? '0') == '1')" class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Invoice Reminder</span>
                                </label>
                            </div>

                            <div>
                                <input type="hidden" name="settings[notify_invoice_overdue]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_invoice_overdue]" value="1" @checked(($settings['notify_invoice_overdue'] ?? '0') == '1')" class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Invoice Overdue</span>
                                </label>
                            </div>

                            <div>
                                <input type="hidden" name="settings[notify_service_activated]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_service_activated]" value="1" @checked(($settings['notify_service_activated'] ?? '0') == '1')" class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Service Activated</span>
                                </label>
                            </div>

                            <div>
                                <input type="hidden" name="settings[notify_service_terminated]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_service_terminated]" value="1" @checked(($settings['notify_service_terminated'] ?? '0') == '1')" class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Service Terminated</span>
                                </label>
                            </div>

                            <div>
                                <input type="hidden" name="settings[notify_domain_expiry]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_domain_expiry]" value="1" @checked(($settings['notify_domain_expiry'] ?? '0') == '1')" class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Domain Expiry</span>
                                </label>
                            </div>
                        </div>
                    </fieldset>

                    <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                        <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                        <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Save Notifications
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tab: Cron -->
            <div x-show="activeTab === 'cron'" class="space-y-6">
                <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="window.submitForm($el)">
                    @csrf

                    <!-- Status -->
                    @if($cronValidation['valid'])
                        <div class="bg-emerald-50 dark:bg-emerald-950 border border-emerald-200 dark:border-emerald-800 p-4 rounded-lg flex items-start gap-3">
                            <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <div>
                                <p class="font-medium text-emerald-900 dark:text-emerald-300">{{ $cronValidation['message'] }}</p>
                                @if($cronStats)
                                    <p class="text-sm text-emerald-800 dark:text-emerald-400 mt-1">
                                        {{ $cronStats['enabled_jobs'] }} job(s) enabled •
                                        {{ $cronStats['recent_runs_24h'] }} run(s) in last 24h
                                        @if($cronStats['recent_failures_24h'] > 0)
                                            • ⚠️ {{ $cronStats['recent_failures_24h'] }} failure(s)
                                        @endif
                                    </p>
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="bg-red-50 dark:bg-red-950 border border-red-200 dark:border-red-800 p-4 rounded-lg">
                            <p class="font-medium text-red-900 dark:text-red-300">⚠️ Configuration Issues</p>
                            <ul class="text-sm text-red-800 dark:text-red-400 mt-2 space-y-1">
                                @foreach($cronValidation['errors'] as $error)
                                    <li>• {{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Scheduled Tasks Configuration</legend>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Cron Timezone</label>
                                <input type="text" name="settings[cron_timezone]" value="{{ $settings['cron_timezone'] ?? 'UTC' }}" placeholder="e.g., Africa/Nairobi, UTC" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Timezone for scheduling cron jobs (IANA timezone format)</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Log Retention (Days)</label>
                                <input type="number" name="settings[cron_retention_days]" value="{{ $settings['cron_retention_days'] ?? '30' }}" min="1" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Older cron job logs will be automatically deleted</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Max Execution Time (Seconds)</label>
                                <input type="number" name="settings[max_execution_time]" value="{{ $settings['max_execution_time'] ?? '300' }}" min="30" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Maximum time a single cron job can run (prevents hung processes)</p>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Cron Setup Instructions</legend>
                        <div class="space-y-4">
                            <p class="text-sm text-slate-700 dark:text-slate-300">
                                Add one of the following commands to your server's crontab to enable scheduled tasks. Use <code class="bg-slate-100 dark:bg-slate-800 px-2 py-1 rounded text-xs">crontab -e</code> to edit.
                            </p>

                            <!-- Default Command -->
                            <div x-data="{ copied: false }" class="border border-slate-200 dark:border-slate-700 rounded-lg p-4">
                                <div class="flex items-start justify-between gap-3 mb-3">
                                    <div>
                                        <p class="font-medium text-slate-900 dark:text-white">Recommended</p>
                                        <p class="text-sm text-slate-600 dark:text-slate-400">Suppresses output for clean logs</p>
                                    </div>
                                    <button type="button" @click="navigator.clipboard.writeText('{{ $cronCommand }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                            class="px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded text-xs font-medium transition-colors flex items-center gap-2 whitespace-nowrap">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                        </svg>
                                        <span x-text="copied ? 'Copied!' : 'Copy'" class="text-xs"></span>
                                    </button>
                                </div>
                                <code class="block bg-slate-900 text-emerald-400 p-3 rounded text-xs overflow-x-auto font-mono break-all">{{ $cronCommand }}</code>
                            </div>

                            <!-- All Options -->
                            <details class="border border-slate-200 dark:border-slate-700 rounded-lg p-4">
                                <summary class="cursor-pointer font-medium text-slate-900 dark:text-white flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                    View other options
                                </summary>
                                <div class="mt-4 space-y-3">
                                    @foreach($cronCommandOptions as $key => $option)
                                        @if($key !== 'default')
                                            <div x-data="{ copied: false }" class="border border-slate-200 dark:border-slate-700 rounded-lg p-4 bg-slate-50 dark:bg-slate-800">
                                                <div class="flex items-start justify-between gap-3 mb-3">
                                                    <div>
                                                        <p class="font-medium text-slate-900 dark:text-white">{{ $option['label'] }}</p>
                                                        <p class="text-sm text-slate-600 dark:text-slate-400">{{ $option['description'] }}</p>
                                                    </div>
                                                    <button type="button" @click="navigator.clipboard.writeText('{{ addslashes($option['command']) }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                                            class="px-3 py-1.5 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-300 rounded text-xs font-medium transition-colors flex items-center gap-2 whitespace-nowrap">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                                        </svg>
                                                        <span x-text="copied ? 'Copied!' : 'Copy'"></span>
                                                    </button>
                                                </div>
                                                <code class="block bg-slate-900 text-emerald-400 p-3 rounded text-xs overflow-x-auto font-mono break-all">{{ $option['command'] }}</code>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </details>

                            <!-- Help Text -->
                            <div class="bg-blue-50 dark:bg-blue-950 border border-blue-200 dark:border-blue-800 p-3 rounded-lg text-sm text-blue-900 dark:text-blue-300">
                                <p class="font-medium mb-2">💡 How to add to crontab:</p>
                                <ol class="list-decimal list-inside space-y-1 text-xs">
                                    <li>SSH into your server</li>
                                    <li>Run <code class="bg-blue-100 dark:bg-blue-900 px-1 rounded">crontab -e</code></li>
                                    <li>Paste the command and save</li>
                                    <li>Run <code class="bg-blue-100 dark:bg-blue-900 px-1 rounded">crontab -l</code> to verify</li>
                                </ol>
                            </div>
                        </div>
                    </fieldset>

                    <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                        <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                        <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Save Cron Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tab: SMS -->
            <div x-show="activeTab === 'sms'" class="space-y-6">
                <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="window.submitForm($el)">
                    @csrf

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">SMS Configuration</legend>
                        <div class="space-y-4">
                            <div>
                                <input type="hidden" name="settings[sms_enabled]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[sms_enabled]" value="1" @checked(($settings['sms_enabled'] ?? '0') == '1')" class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Enable SMS</span>
                                </label>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">API Token</label>
                                @if($settings['sms_api_token'] ?? false)
                                    <p class="text-sm text-green-600 dark:text-green-400 mb-2">✓ Configured</p>
                                @endif
                                <input type="password" name="settings[sms_api_token]" placeholder="Bearer token from Talksasa" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">
                                    @if($settings['sms_api_token'] ?? false)
                                        A token is configured. Leave blank to keep it.
                                    @else
                                        Enter your SMS API token
                                    @endif
                                </p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Sender ID</label>
                                <input type="text" name="settings[sms_sender_id]" value="{{ $settings['sms_sender_id'] ?? 'TalksasaCloud' }}" placeholder="TalksasaCloud" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Test SMS</legend>
                        <div class="space-y-4">
                            <input type="tel" id="test_phone" placeholder="+254712345678" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            <button type="button" @click="window.sendTestSms()" class="px-4 py-2 bg-slate-600 hover:bg-slate-700 text-white font-medium rounded-lg transition-colors">
                                Send Test SMS
                            </button>
                        </div>
                    </fieldset>

                    <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                        <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                        <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Save SMS
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    async function submitForm(form) {
        const statusEl = form.querySelector('.save-status');
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalHTML = submitBtn.innerHTML;

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<svg class="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Saving...';

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

    async function sendTestEmail() {
        const email = document.getElementById('test_email').value;
        if (!email) {
            alert('Please enter an email address');
            return;
        }

        try {
            const response = await fetch('{{ route("admin.settings.test-smtp") }}', {
                method: 'POST',
                body: JSON.stringify({ email }),
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('input[name="_token"]').value,
                    'Accept': 'application/json'
                }
            });

            if (response.ok) {
                alert('Test email sent successfully!');
            } else {
                alert('Failed to send test email');
            }
        } catch (error) {
            alert('Error: ' + error.message);
        }
    }

    async function sendTestSms() {
        const phone = document.getElementById('test_phone').value;
        if (!phone) {
            alert('Please enter a phone number');
            return;
        }

        try {
            const response = await fetch('{{ route("admin.settings.test-sms") }}', {
                method: 'POST',
                body: JSON.stringify({ phone }),
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('input[name="_token"]').value,
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();
            if (data.success) {
                alert('Test SMS sent successfully!');
            } else {
                alert('Error: ' + (data.message || 'Failed to send SMS'));
            }
        } catch (error) {
            alert('Error: ' + error.message);
        }
    }

    // File uploads for branding
    document.getElementById('logo_upload')?.addEventListener('change', uploadFile.bind(null, 'logo'));
    document.getElementById('favicon_upload')?.addEventListener('change', uploadFile.bind(null, 'favicon'));

    async function uploadFile(type, event) {
        const file = event.target.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('file', file);
        formData.append('type', type);

        try {
            const response = await fetch('{{ route("admin.settings.upload-file") }}', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-Token': document.querySelector('input[name="_token"]').value,
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert('Upload failed: ' + (data.message || 'Unknown error'));
            }
        } catch (error) {
            alert('Error: ' + error.message);
        }
    }
</script>
@endsection

@extends('layouts.admin')

@section('title', 'Settings')

@section('content')
<div class="bg-slate-50 dark:bg-slate-950 py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Settings</h1>
        </div>

        <div x-data="{ activeTab: @js($activeTab) }" class="space-y-6">
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
                    <button @click="activeTab = 'security'" :class="activeTab === 'security' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm whitespace-nowrap">Security</button>
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

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Renewal Invoice Timing</legend>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Controls when automated renewal invoices are created by the daily cron jobs.</p>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Monthly services — days before due date</label>
                                <input type="number" min="1" name="settings[service_monthly_invoice_advance_days]" value="{{ $settings['service_monthly_invoice_advance_days'] ?? '10' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Other billing cycles — days before due date</label>
                                <input type="number" min="1" name="settings[service_renewal_invoice_advance_days]" value="{{ $settings['service_renewal_invoice_advance_days'] ?? '30' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Applies to quarterly, semi-annual, and annual services.</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Domains — days before expiry</label>
                                <input type="number" min="1" name="settings[domain_renewal_advance_days]" value="{{ $settings['domain_renewal_advance_days'] ?? '30' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Reseller packages — days before expiry</label>
                                <input type="number" min="1" name="settings[reseller_package_invoice_advance_days]" value="{{ $settings['reseller_package_invoice_advance_days'] ?? '10' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Renewal invoice is generated this many days before package expiry.</p>
                            </div>
                            <div class="md:col-span-2">
                                <input type="hidden" name="settings[reseller_auto_pay_subscription_from_wallet]" value="0">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="settings[reseller_auto_pay_subscription_from_wallet]" value="1" @checked(in_array($settings['reseller_auto_pay_subscription_from_wallet'] ?? 'true', ['1', 'true', true], true)) class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                    <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Auto-pay package renewal from wallet when balance is sufficient</span>
                                </label>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 ml-6">When a renewal invoice is created, pay it in full from the reseller wallet if funds cover the total. Otherwise the invoice stays unpaid for manual payment.</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Domain renewal — payment window (days)</label>
                                <input type="number" min="1" name="settings[domain_renewal_payment_days]" value="{{ $settings['domain_renewal_payment_days'] ?? '10' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Domain renewal — default years</label>
                                <input type="number" min="1" max="10" name="settings[domain_renewal_years]" value="{{ $settings['domain_renewal_years'] ?? '1' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
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
            <div x-show="activeTab === 'payment_methods'" class="space-y-6" x-data="paymentGateways()">
                <!-- M-Pesa Card -->
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                    <div class="flex items-center justify-between px-6 py-4 bg-gradient-to-r from-blue-50 dark:from-slate-800 to-blue-50/50 dark:to-slate-900 cursor-pointer" @click="open.mpesa = !open.mpesa">
                        <div class="flex items-center gap-4 flex-1">
                            <svg class="w-6 h-6 text-blue-600" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm3.5-9c.83 0 1.5-.67 1.5-1.5S16.33 8 15.5 8 14 8.67 14 9.5s.67 1.5 1.5 1.5zm-7 0c.83 0 1.5-.67 1.5-1.5S9.33 8 8.5 8 7 8.67 7 9.5 7.67 11 8.5 11zm3.5 6.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5z"/></svg>
                            <div class="flex-1">
                                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">M-Pesa (Daraja API)</h3>
                                <p class="text-sm text-slate-600 dark:text-slate-400">Mobile payment via Safaricom</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            @if($gatewayStatus['mpesa'])
                                <span class="px-3 py-1 rounded-full bg-green-100 dark:bg-green-950 text-green-700 dark:text-green-300 text-xs font-medium">ACTIVE ✓</span>
                            @endif
                            <svg :class="open.mpesa ? 'rotate-180' : ''" class="w-5 h-5 text-slate-600 dark:text-slate-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                            </svg>
                        </div>
                    </div>

                    <div x-show="open.mpesa" class="px-6 py-6 border-t border-slate-200 dark:border-slate-800 space-y-4">
                        <form @submit.prevent="saveMpesa($el)" class="space-y-4">
                            @csrf
                            <div>
                                <input type="hidden" name="enabled_hidden" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="mpesa_enabled" value="1" @checked(($settings['mpesa_enabled'] ?? '0') == '1') class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Enable M-Pesa payments</span>
                                </label>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Environment</label>
                                <select name="mpesa_environment" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                                    <option value="sandbox" @selected(($settings['mpesa_environment'] ?? 'sandbox') === 'sandbox')>Sandbox</option>
                                    <option value="production" @selected(($settings['mpesa_environment'] ?? 'sandbox') === 'production')>Production</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Paybill Number</label>
                                <input type="text" name="mpesa_shortcode" value="{{ $settings['mpesa_shortcode'] ?? '' }}" placeholder="123456" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Consumer Key</label>
                                <input type="text" name="mpesa_consumer_key" value="{{ $settings['mpesa_consumer_key'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Consumer Secret (masked)</label>
                                <input type="password" name="mpesa_consumer_secret" placeholder="Leave blank to keep existing" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Passkey (masked)</label>
                                <input type="password" name="mpesa_passkey" placeholder="Leave blank to keep existing" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Callback URL Token</label>
                                <input type="password" name="mpesa_callback_token" value="{{ $settings['mpesa_callback_token'] ?? '' }}" placeholder="Leave blank to keep existing" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Required in production. Appended to the M-Pesa callback URL as <code>?token=...</code>.</p>
                            </div>

                            <!-- URL Registration Section -->
                            <div class="bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800 rounded-lg p-4 space-y-4" x-data="{ showDetails: false }">
                                <div>
                                    <h4 class="font-semibold text-amber-900 dark:text-amber-100 mb-3">🔗 Callback URLs Registration with Safaricom</h4>
                                    <p class="text-xs text-amber-800 dark:text-amber-200">Automatically register callback URLs with Safaricom M-Pesa API</p>
                                </div>

                                @php
                                    $mpesaCallbackToken = $settings['mpesa_callback_token'] ?? '';
                                    $configuredCallbackBase = rtrim($settings['site_url'] ?? config('app.url'), '/');
                                    $runtimeCallbackBase = rtrim(request()->getSchemeAndHttpHost(), '/');
                                    $configuredHost = parse_url($configuredCallbackBase, PHP_URL_HOST);
                                    $runtimeHost = parse_url($runtimeCallbackBase, PHP_URL_HOST);
                                    $hasHostMismatch = !empty($configuredHost) && !empty($runtimeHost) && $configuredHost !== $runtimeHost;
                                    $callbackBase = $hasHostMismatch ? $runtimeCallbackBase : $configuredCallbackBase;
                                    if (($settings['mpesa_environment'] ?? 'sandbox') === 'production' && str_starts_with($callbackBase, 'http://')) {
                                        $callbackBase = 'https://'.substr($callbackBase, 7);
                                    }
                                    $mpesaCallbackUrl = $callbackBase.'/webhooks/c2b'.($mpesaCallbackToken !== '' ? '?token='.urlencode($mpesaCallbackToken) : '');
                                    $callbackHost = parse_url($callbackBase, PHP_URL_HOST);
                                    $currentHost = request()->getHost();
                                @endphp
                                <!-- Callback URL Display -->
                                <div>
                                    <label class="block text-xs font-medium text-amber-900 dark:text-amber-100 mb-2">Your Callback URL</label>
                                    <div class="flex gap-2 items-center">
                                        <input type="text" readonly value="{{ $mpesaCallbackUrl }}" class="flex-1 px-3 py-2 text-sm rounded-lg bg-white dark:bg-slate-800 border border-amber-300 dark:border-amber-700 text-slate-700 dark:text-slate-300 font-mono" />
                                        <button type="button" @click="copyToClipboard('{{ $mpesaCallbackUrl }}')" class="px-3 py-2 rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium transition" title="Copy to clipboard">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                            </svg>
                                        </button>
                                    </div>
                                    <p class="text-xs text-amber-700 dark:text-amber-300 mt-2">
                                        Configured <code>site_url</code>: <strong>{{ $configuredCallbackBase }}</strong>
                                    </p>
                                    @if($hasHostMismatch)
                                        <p class="text-xs text-red-700 dark:text-red-300 mt-2">
                                            Host mismatch detected. Effective callback host is now forced to current domain <strong>{{ $runtimeCallbackBase }}</strong> for registration and runtime safety.
                                        </p>
                                    @endif
                                </div>

                                <!-- Environment Selection -->
                                <div>
                                    <label class="block text-xs font-medium text-amber-900 dark:text-amber-100 mb-2">📍 Target Environment</label>
                                    <div class="grid grid-cols-2 gap-2">
                                        <label class="flex items-center gap-2 p-2 rounded-lg border border-amber-300 dark:border-amber-700 bg-white dark:bg-slate-800 cursor-pointer hover:bg-amber-50 dark:hover:bg-slate-700">
                                            <input type="radio" name="register_environment" value="sandbox" @checked(($settings['mpesa_environment'] ?? 'sandbox') === 'sandbox') class="rounded" />
                                            <span class="text-xs text-amber-900 dark:text-amber-100">🧪 Sandbox (Test)</span>
                                        </label>
                                        <label class="flex items-center gap-2 p-2 rounded-lg border border-amber-300 dark:border-amber-700 bg-white dark:bg-slate-800 cursor-pointer hover:bg-amber-50 dark:hover:bg-slate-700">
                                            <input type="radio" name="register_environment" value="production" @checked(($settings['mpesa_environment'] ?? 'sandbox') === 'production') class="rounded" />
                                            <span class="text-xs text-amber-900 dark:text-amber-100">🚀 Production (Live)</span>
                                        </label>
                                    </div>
                                </div>

                                <!-- Response Type Selection -->
                                <div>
                                    <label class="block text-xs font-medium text-amber-900 dark:text-amber-100 mb-2">⚡ Response Type (if URL unreachable)</label>
                                    <div class="grid grid-cols-2 gap-2">
                                        <label class="flex items-center gap-2 p-2 rounded-lg border border-amber-300 dark:border-amber-700 bg-white dark:bg-slate-800 cursor-pointer hover:bg-amber-50 dark:hover:bg-slate-700">
                                            <input type="radio" name="register_response_type" value="Completed" @checked(($settings['mpesa_register_response_type'] ?? 'Completed') === 'Completed') class="rounded" />
                                            <span class="text-xs text-amber-900 dark:text-amber-100">✅ Auto-Complete</span>
                                        </label>
                                        <label class="flex items-center gap-2 p-2 rounded-lg border border-amber-300 dark:border-amber-700 bg-white dark:bg-slate-800 cursor-pointer hover:bg-amber-50 dark:hover:bg-slate-700">
                                            <input type="radio" name="register_response_type" value="Cancelled" @checked(($settings['mpesa_register_response_type'] ?? 'Completed') === 'Cancelled') class="rounded" />
                                            <span class="text-xs text-amber-900 dark:text-amber-100">❌ Auto-Cancel</span>
                                        </label>
                                    </div>
                                    <p class="text-xs text-amber-700 dark:text-amber-300 mt-2">
                                        If Safaricom can't reach your URL, it will either <strong>auto-complete</strong> (safer) or <strong>auto-cancel</strong> the payment.
                                    </p>
                                </div>

                                <!-- Warnings -->
                                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded p-3">
                                    <p class="text-xs text-red-700 dark:text-red-300 font-medium">⚠️ Important Notes:</p>
                                    <ul class="text-xs text-red-600 dark:text-red-400 mt-2 space-y-1 list-disc list-inside">
                                        <li><strong>Sandbox:</strong> Can register multiple times (safe for testing)</li>
                                        <li><strong>Production:</strong> Can only register ONCE - choose wisely!</li>
                                        <li><strong>HTTPS Required:</strong> Production URLs must use HTTPS</li>
                                        <li><strong>Public Access:</strong> URLs must be accessible from the internet</li>
                                        <li><strong>Paybill Format:</strong> Must be numeric (e.g., 123456)</li>
                                    </ul>
                                </div>

                                <!-- Troubleshooting -->
                                <div class="bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded p-3 space-y-2">
                                    <p class="text-xs text-orange-700 dark:text-orange-300 font-medium">🔧 Troubleshooting HTTP 400 Errors:</p>
                                    <div class="text-xs text-orange-600 dark:text-orange-400 space-y-2">
                                        <div>
                                            <p class="font-medium">Error 400.003.02 - Validation Error</p>
                                            <ul class="list-disc list-inside space-y-1 ml-2">
                                                <li>Paybill number must be <strong>numeric only</strong> (no letters/symbols)</li>
                                                <li>Callback URL must be <strong>valid HTTPS</strong> and publicly accessible</li>
                                                <li>Check that your domain's <strong>SSL certificate is valid</strong></li>
                                                <li>Ensure <strong>firewall/VPN allows</strong> outbound to Safaricom</li>
                                                <li>Try <strong>pinging your callback URL</strong> from the internet</li>
                                            </ul>
                                        </div>
                                        <div>
                                            <p class="font-medium">Testing Callback URL Accessibility</p>
                                            <code class="block text-xs bg-white dark:bg-slate-900 p-2 rounded mt-1 overflow-auto">curl -v "{{ $mpesaCallbackUrl }}"</code>
                                        </div>
                                    </div>
                                </div>

                                <!-- Registration Button -->
                                <button type="button" @click="registerMpesaUrls()" :disabled="registering.mpesa" class="w-full px-4 py-3 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium transition disabled:opacity-50 flex items-center justify-center gap-2">
                                    <span x-show="!registering.mpesa">
                                        <svg class="w-5 h-5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.658 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                                        </svg>
                                        Register URLs with Safaricom
                                    </span>
                                    <span x-show="registering.mpesa" class="inline-flex items-center gap-2">
                                        <svg class="animate-spin h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Registering...
                                    </span>
                                </button>

                                <!-- Status Message -->
                                <p x-show="status.registration" :class="status.registration?.type === 'success' ? 'text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/20' : 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20'" class="text-sm mt-3 p-3 rounded-lg" x-text="status.registration?.message"></p>

                                <!-- Additional Info Toggle -->
                                <button type="button" @click="showDetails = !showDetails" class="text-xs text-amber-700 dark:text-amber-300 hover:text-amber-900 dark:hover:text-amber-200 mt-2">
                                    <span x-show="!showDetails">ℹ️ Show additional information</span>
                                    <span x-show="showDetails">ℹ️ Hide additional information</span>
                                </button>

                                <!-- Additional Information -->
                                <div x-show="showDetails" x-transition class="bg-white dark:bg-slate-800 rounded p-3 border border-amber-200 dark:border-amber-800 space-y-2">
                                    <div>
                                        <p class="text-xs font-medium text-slate-700 dark:text-slate-300 mb-2">📋 What this does:</p>
                                        <ol class="text-xs text-slate-600 dark:text-slate-400 space-y-1 list-decimal list-inside">
                                            <li>Authenticates with Safaricom using your Consumer Key & Secret</li>
                                            <li>Registers your callback URL as the Confirmation URL</li>
                                            <li>Enables M-Pesa C2B (Customer to Business) payments</li>
                                            <li>Payments will be routed to your system automatically</li>
                                        </ol>
                                    </div>
                                    <div>
                                        <p class="text-xs font-medium text-slate-700 dark:text-slate-300 mb-2">🔍 Current Settings:</p>
                                        <table class="text-xs text-slate-600 dark:text-slate-400 w-full">
                                            <tr><td class="font-medium">Paybill #:</td><td>{{ $settings['mpesa_shortcode'] ?? 'Not configured' }}</td></tr>
                                            <tr><td class="font-medium">Environment:</td><td>{{ ucfirst($settings['mpesa_environment'] ?? 'sandbox') }}</td></tr>
                                            <tr><td class="font-medium">Consumer Key:</td><td>{{ !empty($settings['mpesa_consumer_key']) ? '✓ Configured' : '✗ Missing' }}</td></tr>
                                            <tr><td class="font-medium">Consumer Secret:</td><td>{{ !empty($settings['mpesa_consumer_secret']) ? '✓ Configured' : '✗ Missing' }}</td></tr>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- Test Payment Simulation (Sandbox only) -->
                            @if(($settings['mpesa_environment'] ?? 'sandbox') === 'sandbox')
                            <div class="bg-blue-50 dark:bg-blue-900/10 border border-blue-200 dark:border-blue-800 rounded-lg p-4 space-y-4 mt-4">
                                <div>
                                    <h4 class="font-semibold text-blue-900 dark:text-blue-100 mb-2">🧪 Test Payment (Sandbox Only)</h4>
                                    <p class="text-xs text-blue-800 dark:text-blue-200 mb-4">Simulate a customer payment to test your callback URL registration</p>
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-medium text-blue-900 dark:text-blue-100 mb-2">Phone Number</label>
                                        <input type="text" id="test_phone" placeholder="254712345678" x-model="testPayment.phone" class="w-full px-3 py-2 text-sm rounded-lg border border-blue-300 dark:border-blue-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                                        <p class="text-xs text-blue-700 dark:text-blue-300 mt-1">Format: 254XXXXXXXXX or 0XXXXXXXXX</p>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-medium text-blue-900 dark:text-blue-100 mb-2">Amount (KES)</label>
                                        <input type="number" id="test_amount" placeholder="100" x-model.number="testPayment.amount" min="1" max="999999" class="w-full px-3 py-2 text-sm rounded-lg border border-blue-300 dark:border-blue-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                                        <p class="text-xs text-blue-700 dark:text-blue-300 mt-1">Minimum: 1 KES</p>
                                    </div>
                                </div>

                                <button type="button" @click="simulateMpesaPayment()" :disabled="simulating.mpesa" class="w-full px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium transition disabled:opacity-50 flex items-center justify-center gap-2">
                                    <span x-show="!simulating.mpesa">
                                        <svg class="w-5 h-5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M7.172 7.172A4 4 0 1014.828 14.828"/>
                                        </svg>
                                        Send Test Payment
                                    </span>
                                    <span x-show="simulating.mpesa" class="inline-flex items-center gap-2">
                                        <svg class="animate-spin h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Simulating...
                                    </span>
                                </button>

                                <p x-show="status.simulation" :class="status.simulation?.type === 'success' ? 'text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/20' : 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20'" class="text-sm p-3 rounded-lg" x-text="status.simulation?.message"></p>

                                <div class="bg-white dark:bg-slate-800 rounded p-3 border border-blue-200 dark:border-blue-800">
                                    <p class="text-xs font-medium text-slate-700 dark:text-slate-300 mb-2">📝 How to test:</p>
                                    <ol class="text-xs text-slate-600 dark:text-slate-400 space-y-1 list-decimal list-inside">
                                        <li>Enter a test phone number (use sandbox test numbers)</li>
                                        <li>Enter a test amount</li>
                                        <li>Click "Send Test Payment"</li>
                                        <li>Check your callback URL logs to verify M-Pesa sent the payment notification</li>
                                        <li>Verify that your payment webhook handler processed it correctly</li>
                                    </ol>
                                </div>
                            </div>
                            @endif

                            <div class="flex gap-3 pt-4">
                                <button type="button" @click="testMpesa()" :disabled="testing.mpesa" class="px-4 py-2 rounded-lg bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-900 dark:text-white font-medium transition disabled:opacity-50">
                                    <span x-show="!testing.mpesa">Test Connection</span>
                                    <span x-show="testing.mpesa" class="inline-flex items-center gap-2">
                                        <svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                                        Testing...
                                    </span>
                                </button>
                                <button type="submit" :disabled="saving.mpesa" class="flex-1 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium transition disabled:opacity-50">
                                    <span x-show="!saving.mpesa">Save M-Pesa Settings</span>
                                    <span x-show="saving.mpesa" class="inline-flex items-center justify-center gap-2 w-full">
                                        <svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                                        Saving...
                                    </span>
                                </button>
                            </div>
                            <p x-show="status.mpesa" :class="status.mpesa?.type === 'success' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'" class="text-sm mt-2" x-text="status.mpesa?.message"></p>
                        </form>
                    </div>
                </div>

                <!-- Stripe Card -->
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                    <div class="flex items-center justify-between px-6 py-4 bg-gradient-to-r from-purple-50 dark:from-slate-800 to-purple-50/50 dark:to-slate-900 cursor-pointer" @click="open.stripe = !open.stripe">
                        <div class="flex items-center gap-4 flex-1">
                            <svg class="w-6 h-6 text-purple-600" fill="currentColor" viewBox="0 0 24 24"><path d="M23.014 12.233c0 6.14-4.934 11.167-11.014 11.167C5.933 23.4 1 18.373 1 12.233c0-6.14 4.933-11.167 11.014-11.167 6.08 0 11.014 5.027 11.014 11.167zm-6.49 1.134H7.28V10.85h9.244v2.517z"/></svg>
                            <div class="flex-1">
                                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Stripe</h3>
                                <p class="text-sm text-slate-600 dark:text-slate-400">Credit & debit card payments</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            @if($gatewayStatus['stripe'])
                                <span class="px-3 py-1 rounded-full bg-green-100 dark:bg-green-950 text-green-700 dark:text-green-300 text-xs font-medium">ACTIVE ✓</span>
                            @endif
                            <svg :class="open.stripe ? 'rotate-180' : ''" class="w-5 h-5 text-slate-600 dark:text-slate-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                            </svg>
                        </div>
                    </div>

                    <div x-show="open.stripe" class="px-6 py-6 border-t border-slate-200 dark:border-slate-800 space-y-4">
                        <form @submit.prevent="saveStripe($el)" class="space-y-4">
                            @csrf
                            <div>
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="stripe_enabled" value="1" @checked(($settings['stripe_enabled'] ?? '0') == '1') class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Enable Stripe payments</span>
                                </label>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Secret Key (masked)</label>
                                <input type="password" name="stripe_secret_key" placeholder="Leave blank to keep existing" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Publishable Key</label>
                                <input type="text" name="stripe_publishable_key" value="{{ $settings['stripe_publishable_key'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Webhook Secret (masked)</label>
                                <input type="password" name="stripe_webhook_secret" placeholder="Leave blank to keep existing" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div class="flex gap-3 pt-4">
                                <button type="submit" :disabled="saving.stripe" class="flex-1 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium transition disabled:opacity-50">
                                    <span x-show="!saving.stripe">Save Stripe Settings</span>
                                    <span x-show="saving.stripe" class="inline-flex items-center justify-center gap-2 w-full">
                                        <svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                                        Saving...
                                    </span>
                                </button>
                            </div>
                            <p x-show="status.stripe" :class="status.stripe?.type === 'success' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'" class="text-sm mt-2" x-text="status.stripe?.message"></p>
                        </form>
                    </div>
                </div>

                <!-- PayPal Card -->
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                    <div class="flex items-center justify-between px-6 py-4 bg-gradient-to-r from-yellow-50 dark:from-slate-800 to-yellow-50/50 dark:to-slate-900 cursor-pointer" @click="open.paypal = !open.paypal">
                        <div class="flex items-center gap-4 flex-1">
                            <svg class="w-6 h-6 text-yellow-600" fill="currentColor" viewBox="0 0 24 24"><path d="M9.343 17.657c0-1.328 2.686-2.404 6-2.404s6 1.076 6 2.404m0-6c0 1.328-2.686 2.404-6 2.404s-6-1.076-6-2.404m0-6c0 1.328 2.686 2.404 6 2.404s6-1.076 6-2.404M3 5.657c0-1.328 2.686-2.404 6-2.404s6 1.076 6 2.404v10c0 1.328-2.686 2.404-6 2.404s-6-1.076-6-2.404V5.657z"/></svg>
                            <div class="flex-1">
                                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">PayPal</h3>
                                <p class="text-sm text-slate-600 dark:text-slate-400">PayPal wallet & balance payments</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            @if($gatewayStatus['paypal'])
                                <span class="px-3 py-1 rounded-full bg-green-100 dark:bg-green-950 text-green-700 dark:text-green-300 text-xs font-medium">ACTIVE ✓</span>
                            @endif
                            <svg :class="open.paypal ? 'rotate-180' : ''" class="w-5 h-5 text-slate-600 dark:text-slate-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                            </svg>
                        </div>
                    </div>

                    <div x-show="open.paypal" class="px-6 py-6 border-t border-slate-200 dark:border-slate-800 space-y-4">
                        <form @submit.prevent="savePayPal($el)" class="space-y-4">
                            @csrf
                            <div>
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="paypal_enabled" value="1" @checked(($settings['paypal_enabled'] ?? '0') == '1') class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Enable PayPal payments</span>
                                </label>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Environment</label>
                                <select name="paypal_environment" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                                    <option value="sandbox" @selected(($settings['paypal_environment'] ?? 'sandbox') === 'sandbox')>Sandbox</option>
                                    <option value="production" @selected(($settings['paypal_environment'] ?? 'sandbox') === 'production')>Production</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Client ID</label>
                                <input type="text" name="paypal_client_id" value="{{ $settings['paypal_client_id'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Client Secret (masked)</label>
                                <input type="password" name="paypal_client_secret" placeholder="Leave blank to keep existing" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Webhook ID</label>
                                <input type="text" name="paypal_webhook_id" value="{{ $settings['paypal_webhook_id'] ?? '' }}" placeholder="Optional: webhook listener ID" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div class="flex gap-3 pt-4">
                                <button type="submit" :disabled="saving.paypal" class="flex-1 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium transition disabled:opacity-50">
                                    <span x-show="!saving.paypal">Save PayPal Settings</span>
                                    <span x-show="saving.paypal" class="inline-flex items-center justify-center gap-2 w-full">
                                        <svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                                        Saving...
                                    </span>
                                </button>
                            </div>
                            <p x-show="status.paypal" :class="status.paypal?.type === 'success' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'" class="text-sm mt-2" x-text="status.paypal?.message"></p>
                        </form>
                    </div>
                </div>

                <!-- Bank Transfer Card -->
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                    <div class="flex items-center justify-between px-6 py-4 bg-gradient-to-r from-green-50 dark:from-slate-800 to-green-50/50 dark:to-slate-900 cursor-pointer" @click="open.bank = !open.bank">
                        <div class="flex items-center gap-4 flex-1">
                            <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h10m4 0a1 1 0 01-1 1H4a1 1 0 01-1-1m0 0a1 1 0 011-1h16a1 1 0 011 1m0 0v2a2 2 0 01-2 2H5a2 2 0 01-2-2v-2m0-5V7a2 2 0 012-2h14a2 2 0 012 2v3"/></svg>
                            <div class="flex-1">
                                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Bank Transfer</h3>
                                <p class="text-sm text-slate-600 dark:text-slate-400">Direct bank account details</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            @if($settings['bank_transfer_enabled'] ?? false)
                                <span class="px-3 py-1 rounded-full bg-green-100 dark:bg-green-950 text-green-700 dark:text-green-300 text-xs font-medium">ACTIVE ✓</span>
                            @endif
                            <svg :class="open.bank ? 'rotate-180' : ''" class="w-5 h-5 text-slate-600 dark:text-slate-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                            </svg>
                        </div>
                    </div>

                    <div x-show="open.bank" class="px-6 py-6 border-t border-slate-200 dark:border-slate-800 space-y-4">
                        <form @submit.prevent="saveBank($el)" class="space-y-4">
                            @csrf
                            <div>
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="bank_transfer_enabled" value="1" @checked(($settings['bank_transfer_enabled'] ?? '0') == '1') class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Enable bank transfer payments</span>
                                </label>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Bank Name</label>
                                <input type="text" name="bank_name" value="{{ $settings['bank_name'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Account Name</label>
                                <input type="text" name="bank_account_name" value="{{ $settings['bank_account_name'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Account Number</label>
                                <input type="text" name="bank_account_number" value="{{ $settings['bank_account_number'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Branch</label>
                                <input type="text" name="bank_branch" value="{{ $settings['bank_branch'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">SWIFT Code</label>
                                <input type="text" name="bank_swift_code" value="{{ $settings['bank_swift_code'] ?? '' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div class="flex gap-3 pt-4">
                                <button type="submit" :disabled="saving.bank" class="flex-1 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium transition disabled:opacity-50">
                                    <span x-show="!saving.bank">Save Bank Settings</span>
                                    <span x-show="saving.bank" class="inline-flex items-center justify-center gap-2 w-full">
                                        <svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                                        Saving...
                                    </span>
                                </button>
                            </div>
                            <p x-show="status.bank" :class="status.bank?.type === 'success' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'" class="text-sm mt-2" x-text="status.bank?.message"></p>
                        </form>
                    </div>
                </div>
            </div>

            <script>
                function paymentGateways() {
                    return {
                        open: { mpesa: true, stripe: false, paypal: false, bank: false },
                        saving: { mpesa: false, stripe: false, paypal: false, bank: false },
                        testing: { mpesa: false },
                        registering: { mpesa: false },
                        simulating: { mpesa: false },
                        status: { mpesa: null, stripe: null, paypal: null, bank: null, registration: null, simulation: null },
                        testPayment: { phone: '', amount: 100 },

                        async saveMpesa(form) {
                            await this.saveForm(form, 'mpesa');
                        },
                        async saveStripe(form) {
                            await this.saveForm(form, 'stripe');
                        },
                        async savePayPal(form) {
                            await this.saveForm(form, 'paypal');
                        },
                        async saveBank(form) {
                            await this.saveForm(form, 'bank');
                        },

                        async saveForm(form, gateway) {
                            this.saving[gateway] = true;
                            const settings = {};

                            form.querySelectorAll('input, select, textarea').forEach((field) => {
                                const key = field.name;
                                if (! key || key === '_token' || key === 'enabled_hidden' || key.startsWith('register_')) {
                                    return;
                                }

                                if (field.type === 'checkbox') {
                                    settings[key] = field.checked ? (field.value || '1') : '0';

                                    return;
                                }

                                if (field.type === 'radio') {
                                    if (field.checked) {
                                        settings[key] = field.value;
                                    }

                                    return;
                                }

                                settings[key] = field.value;
                            });

                            try {
                                const response = await fetch('{{ route("admin.settings.update") }}', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'Accept': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}',
                                        'X-Requested-With': 'XMLHttpRequest'
                                    },
                                    body: JSON.stringify({ settings })
                                });

                                const data = await response.json();

                                if (response.ok && data.success) {
                                    this.status[gateway] = { type: 'success', message: data.message || 'Settings saved successfully!' };
                                } else {
                                    const errorMsg = data.message
                                        || (data.errors ? Object.values(data.errors).flat().join(' ') : 'Failed to save settings');
                                    this.status[gateway] = { type: 'error', message: errorMsg };
                                    console.error('Settings save error:', { status: response.status, data });
                                }
                            } catch (error) {
                                this.status[gateway] = { type: 'error', message: 'Error: ' + error.message };
                                console.error('Settings save exception:', error);
                            } finally {
                                this.saving[gateway] = false;
                                setTimeout(() => { this.status[gateway] = null; }, 3000);
                            }
                        },

                        async testMpesa() {
                            this.testing.mpesa = true;
                            try {
                                const response = await fetch('{{ route("admin.settings.test-mpesa") }}', {
                                    method: 'POST',
                                    headers: {
                                        'X-CSRF-Token': '{{ csrf_token() }}',
                                        'X-Requested-With': 'XMLHttpRequest'
                                    }
                                });

                                const data = await response.json();
                                this.status.mpesa = {
                                    type: data.success ? 'success' : 'error',
                                    message: data.message + (data.environment ? ` (${data.environment})` : '')
                                };
                            } catch (error) {
                                this.status.mpesa = { type: 'error', message: 'Test failed: ' + error.message };
                            } finally {
                                this.testing.mpesa = false;
                                setTimeout(() => { this.status.mpesa = null; }, 5000);
                            }
                        },

                        copyToClipboard(text) {
                            navigator.clipboard.writeText(text).then(() => {
                                const toast = document.createElement('div');
                                toast.className = 'fixed bottom-4 right-4 px-4 py-2 bg-green-600 text-white rounded-lg text-sm';
                                toast.textContent = 'URL copied to clipboard!';
                                document.body.appendChild(toast);
                                setTimeout(() => toast.remove(), 2000);
                            }).catch(err => {
                                console.error('Failed to copy:', err);
                            });
                        },

                        async registerMpesaUrls() {
                            this.registering.mpesa = true;

                            // Get selected environment and response type from form
                            const environment = document.querySelector('input[name="register_environment"]:checked')?.value || 'sandbox';
                            const responseType = document.querySelector('input[name="register_response_type"]:checked')?.value || 'Completed';

                            // Confirm production registration
                            if (environment === 'production') {
                                if (!await window.appConfirm(
                                    '⚠️ WARNING: You are registering URLs for PRODUCTION!\n\nThis will enable LIVE M-Pesa transactions.\nYou can only register once in production.\n\nMake sure:\n✅ Your system is ready for live payments\n✅ Your SSL certificate is valid\n✅ Your URLs are publicly accessible\n\nResponse Type: ' + responseType + '\n\nContinue?',
                                    'Production M-Pesa registration',
                                    'Continue'
                                )) {
                                    this.registering.mpesa = false;
                                    return;
                                }
                            }

                            try {
                                // Keep runtime environment aligned with selected registration target.
                                const envSave = await fetch('{{ route("admin.settings.update") }}', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'Accept': 'application/json',
                                        'X-CSRF-Token': '{{ csrf_token() }}',
                                        'X-Requested-With': 'XMLHttpRequest'
                                    },
                                    body: JSON.stringify({
                                        settings: {
                                            site_url: window.location.origin,
                                            mpesa_environment: environment,
                                            mpesa_register_response_type: responseType
                                        }
                                    })
                                });
                                const envData = await envSave.json();
                                if (!envSave.ok || !envData.success) {
                                    this.status.registration = {
                                        type: 'error',
                                        message: envData.message || 'Failed to save M-Pesa environment before URL registration.'
                                    };
                                    this.registering.mpesa = false;
                                    return;
                                }

                                const response = await fetch('{{ route("admin.settings.register-mpesa-urls") }}', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-Token': '{{ csrf_token() }}',
                                        'X-Requested-With': 'XMLHttpRequest'
                                    },
                                    body: JSON.stringify({
                                        environment: environment,
                                        response_type: responseType
                                    })
                                });

                                const data = await response.json();

                                this.status.registration = {
                                    type: data.success ? 'success' : 'error',
                                    message: data.message
                                };

                                if (data.success) {
                                    // Reflect saved values in page state immediately.
                                    const envSelect = document.querySelector('select[name="mpesa_environment"]');
                                    if (envSelect) {
                                        envSelect.value = environment;
                                    }
                                    // Keep success message visible longer
                                    setTimeout(() => { this.status.registration = null; }, 5000);
                                } else {
                                    setTimeout(() => { this.status.registration = null; }, 4000);
                                }
                            } catch (error) {
                                this.status.registration = {
                                    type: 'error',
                                    message: 'Registration failed: ' + error.message
                                };
                                setTimeout(() => { this.status.registration = null; }, 4000);
                            } finally {
                                this.registering.mpesa = false;
                            }
                        },

                        async simulateMpesaPayment() {
                            if (!this.testPayment.phone || !this.testPayment.amount) {
                                alert('Please enter both phone number and amount');
                                return;
                            }

                            this.simulating.mpesa = true;

                            try {
                                const response = await fetch('{{ route("admin.settings.simulate-mpesa-payment") }}', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-Token': '{{ csrf_token() }}',
                                        'X-Requested-With': 'XMLHttpRequest'
                                    },
                                    body: JSON.stringify({
                                        phone_number: this.testPayment.phone,
                                        amount: this.testPayment.amount
                                    })
                                });

                                const data = await response.json();

                                this.status.simulation = {
                                    type: data.success ? 'success' : 'error',
                                    message: data.message + (data.note ? '\n\n' + data.note : '')
                                };

                                if (data.success) {
                                    setTimeout(() => { this.status.simulation = null; }, 6000);
                                } else {
                                    setTimeout(() => { this.status.simulation = null; }, 4000);
                                }
                            } catch (error) {
                                this.status.simulation = {
                                    type: 'error',
                                    message: 'Simulation failed: ' + error.message
                                };
                                setTimeout(() => { this.status.simulation = null; }, 4000);
                            } finally {
                                this.simulating.mpesa = false;
                            }
                        }
                    };
                }
            </script>

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
                                    <input type="checkbox" name="settings[suspend_on_overdue]" value="1" @checked(in_array($settings['suspend_on_overdue'] ?? 'true', ['1', 'true'], true)) class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Suspend service on overdue invoice (direct and reseller customers)</span>
                                </label>
                            </div>

                            <div>
                                <input type="hidden" name="settings[suspend_on_disk_overquota]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[suspend_on_disk_overquota]" value="1" @checked(in_array($settings['suspend_on_disk_overquota'] ?? 'true', ['1', 'true'], true)) class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Suspend DirectAdmin hosting when disk quota is exceeded</span>
                                </label>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Disk overquota threshold (%)</label>
                                <input type="number" name="settings[disk_overquota_threshold_percent]" min="1" max="200" value="{{ $settings['disk_overquota_threshold_percent'] ?? '100' }}" class="block w-full max-w-xs px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">100 suspends at the package limit. Lower values suspend earlier; unlimited quotas are never auto-suspended.</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Months Until Automatic Termination</label>
                                <input type="number" name="settings[terminate_after_unpaid_months]" min="1" max="24" value="{{ $settings['terminate_after_unpaid_months'] ?? '4' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Services are terminated when their linked invoice stays unpaid or overdue for this many months after the due date.</p>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Reseller Package Enforcement</legend>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Controls suspension of reseller accounts and their managed hosting on DirectAdmin when package billing lapses or limits are exceeded.</p>
                        <div class="space-y-3">
                            <div>
                                <input type="hidden" name="settings[reseller_suspend_on_overdue]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[reseller_suspend_on_overdue]" value="1" @checked(in_array($settings['reseller_suspend_on_overdue'] ?? 'true', ['1', 'true'], true)) class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Suspend reseller when package subscription is overdue or expired (after grace period)</span>
                                </label>
                            </div>
                            <div>
                                <input type="hidden" name="settings[reseller_cascade_suspend_on_overdue]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[reseller_cascade_suspend_on_overdue]" value="1" @checked(in_array($settings['reseller_cascade_suspend_on_overdue'] ?? 'true', ['1', 'true'], true)) class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Cascade suspend managed hosting on DirectAdmin when reseller is suspended</span>
                                </label>
                            </div>
                            <div>
                                <input type="hidden" name="settings[reseller_suspend_excess_services]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[reseller_suspend_excess_services]" value="1" @checked(in_array($settings['reseller_suspend_excess_services'] ?? 'true', ['1', 'true'], true)) class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Daily cron: suspend services beyond package service slot limit</span>
                                </label>
                            </div>
                            <div>
                                <input type="hidden" name="settings[reseller_enforce_limits_on_provision]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[reseller_enforce_limits_on_provision]" value="1" @checked(in_array($settings['reseller_enforce_limits_on_provision'] ?? 'true', ['1', 'true'], true)) class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Block provisioning when reseller is suspended or at service limit</span>
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
                            Save Provisioning
                        </button>
                    </div>
                </form>

                @include('admin.settings.partials.directadmin-nameservers-card')
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
                        <div class="mb-4 p-4 rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950/30">
                            <p class="text-sm font-medium text-blue-900 dark:text-blue-200">Zoho Mail quick setup</p>
                            <p class="text-xs text-blue-800 dark:text-blue-300 mt-1">
                                Use your full Zoho mailbox as SMTP username. If 2FA is enabled, use an app password.
                            </p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" onclick="window.applyZohoPreset('paid')" class="px-3 py-1.5 text-xs rounded bg-blue-600 text-white hover:bg-blue-700">
                                    Use Zoho Paid (smtppro.zoho.com)
                                </button>
                                <button type="button" onclick="window.applyZohoPreset('personal')" class="px-3 py-1.5 text-xs rounded bg-slate-600 text-white hover:bg-slate-700">
                                    Use Zoho Personal/Free (smtp.zoho.com)
                                </button>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">SMTP Host</label>
                                <input type="text" name="settings[smtp_host]" value="{{ $settings['smtp_host'] ?? '' }}" placeholder="smtppro.zoho.com" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">SMTP Port</label>
                                <input type="number" name="settings[smtp_port]" value="{{ $settings['smtp_port'] ?? '587' }}" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Common ports: 25 (plain), 587 (TLS), 465 (SSL)</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Encryption</label>
                                <select name="settings[smtp_encryption]" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                                    <option value="tls" @selected(($settings['smtp_encryption'] ?? 'tls') === 'tls')>TLS (STARTTLS - Port 587)</option>
                                    <option value="ssl" @selected(($settings['smtp_encryption'] ?? 'tls') === 'ssl')>SSL (Implicit SSL - Port 465)</option>
                                    <option value="" @selected(($settings['smtp_encryption'] ?? 'tls') === '')>None (Plain - Port 25)</option>
                                </select>
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
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">
                                    For Zoho, this should match your authenticated mailbox or an allowed alias to avoid relay errors.
                                </p>
                            </div>

                            <div class="pt-3 border-t border-slate-200 dark:border-slate-700">
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Reply-To Address (for customer replies)</label>
                                <input type="email" name="settings[mail_reply_to_address]" value="{{ $settings['mail_reply_to_address'] ?? ($settings['support_email'] ?? '') }}" placeholder="support@yourdomain.com" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">
                                    Users replying to system emails will reply to this address.
                                </p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Reply-To Name</label>
                                <input type="text" name="settings[mail_reply_to_name]" value="{{ $settings['mail_reply_to_name'] ?? ($settings['mail_from_name'] ?? 'Support Team') }}" placeholder="Support Team" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" />
                            </div>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Delivery</legend>
                        <div>
                            <input type="hidden" name="settings[email_queue_enabled]" value="0">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="settings[email_queue_enabled]" value="1" @checked(in_array($settings['email_queue_enabled'] ?? 'true', ['1', 'true'], true)) class="rounded" />
                                <span class="text-slate-700 dark:text-slate-300">Queue outbound emails (requires a queue worker)</span>
                            </label>
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

                <!-- Email Templates -->
                @include('admin.settings.partials.email-templates-card')
            </div>
            <div x-show="activeTab === 'notifications'" class="space-y-6">
                <!-- Notification Triggers Form -->
                <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="window.submitForm($el)">
                    @csrf

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Notification Triggers</legend>
                        <div class="space-y-3">
                            <div>
                                <input type="hidden" name="settings[notify_new_order]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_new_order]" value="1" @checked(($settings['notify_new_order'] ?? '0') == '1') class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">New Order</span>
                                </label>
                            </div>

                            <div>
                                <input type="hidden" name="settings[notify_payment]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_payment]" value="1" @checked(($settings['notify_payment'] ?? '0') == '1') class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Payment Received</span>
                                </label>
                            </div>

                            <div>
                                <input type="hidden" name="settings[notify_service_suspend]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_service_suspend]" value="1" @checked(($settings['notify_service_suspend'] ?? '0') == '1') class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Service Suspended</span>
                                </label>
                            </div>

                            <div>
                                <input type="hidden" name="settings[notify_ticket]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_ticket]" value="1" @checked(($settings['notify_ticket'] ?? '0') == '1') class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">New Ticket</span>
                                </label>
                            </div>

                            <div>
                                <input type="hidden" name="settings[notify_invoice_generated]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_invoice_generated]" value="1" @checked(($settings['notify_invoice_generated'] ?? '0') == '1') class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Invoice Generated</span>
                                </label>
                            </div>

                            <div>
                                <input type="hidden" name="settings[notify_invoice_reminder]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_invoice_reminder]" value="1" @checked(($settings['notify_invoice_reminder'] ?? '0') == '1') class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Invoice Reminder</span>
                                </label>
                            </div>

                            <div>
                                <input type="hidden" name="settings[notify_invoice_overdue]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_invoice_overdue]" value="1" @checked(($settings['notify_invoice_overdue'] ?? '0') == '1') class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Invoice Overdue</span>
                                </label>
                            </div>

                            <div>
                                <input type="hidden" name="settings[notify_service_activated]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_service_activated]" value="1" @checked(($settings['notify_service_activated'] ?? '0') == '1') class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Service Activated</span>
                                </label>
                            </div>

                            <div>
                                <input type="hidden" name="settings[notify_service_terminated]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_service_terminated]" value="1" @checked(($settings['notify_service_terminated'] ?? '0') == '1') class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Service Terminated</span>
                                </label>
                            </div>

                            <div>
                                <input type="hidden" name="settings[notify_domain_expiry]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_domain_expiry]" value="1" @checked(($settings['notify_domain_expiry'] ?? '0') == '1') class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Domain Expiry</span>
                                </label>
                            </div>
                            <div>
                                <input type="hidden" name="settings[notify_service_unsuspended]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_service_unsuspended]" value="1" @checked(($settings['notify_service_unsuspended'] ?? '0') == '1') class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Service Restored</span>
                                </label>
                            </div>
                            <div>
                                <input type="hidden" name="settings[notify_admin_manual_payment]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_admin_manual_payment]" value="1" @checked(($settings['notify_admin_manual_payment'] ?? '0') == '1') class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Admin: Manual Payment Submitted</span>
                                </label>
                            </div>
                            <div>
                                <input type="hidden" name="settings[notify_admin_reseller_domain_push]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_admin_reseller_domain_push]" value="1" @checked(($settings['notify_admin_reseller_domain_push'] ?? '0') == '1') class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Admin: Reseller Domain Push</span>
                                </label>
                            </div>
                            <div>
                                <input type="hidden" name="settings[notify_reseller_wallet_low]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[notify_reseller_wallet_low]" value="1" @checked(($settings['notify_reseller_wallet_low'] ?? '0') == '1') class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Reseller: Low Wallet Balance</span>
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
                            Save Notification Triggers
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
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                    <div class="px-6 py-5 border-b border-slate-200 dark:border-slate-800">
                        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">SMS Configuration</h2>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Talksasa SMS API credentials and test sending.</p>
                    </div>
                    <form method="POST" action="{{ route('admin.settings.update') }}" class="p-6 space-y-6" @submit.prevent="window.submitForm($el)">
                    @csrf

                    <fieldset>
                        <legend class="text-base font-semibold text-slate-900 dark:text-white mb-4">Provider settings</legend>
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

                @include('admin.settings.partials.sms-templates-card')
            </div>
            <div x-show="activeTab === 'security'" class="space-y-6">
                <form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="window.submitForm($el)">
                    @csrf

                    <fieldset>
                        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-4">reCAPTCHA v3</legend>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Protect registration form from bot attacks using Google reCAPTCHA v3. Get keys at <a href="https://www.google.com/recaptcha/admin" target="_blank" class="text-blue-600 dark:text-blue-400 hover:underline">https://www.google.com/recaptcha/admin</a></p>

                        <div class="space-y-4">
                            <div>
                                <input type="hidden" name="settings[recaptcha_enabled]" value="0">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="settings[recaptcha_enabled]" value="1" @checked(($settings['recaptcha_enabled'] ?? '0') == '1') class="rounded" />
                                    <span class="text-slate-700 dark:text-slate-300">Enable reCAPTCHA for registration</span>
                                </label>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Site Key</label>
                                <input type="text" name="settings[recaptcha_site_key]" value="{{ $settings['recaptcha_site_key'] ?? '' }}" placeholder="6Le-XXXX..." class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white font-mono text-sm" />
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Public key from Google reCAPTCHA console</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Secret Key</label>
                                @if($settings['recaptcha_secret_key'] ?? false)
                                    <p class="text-sm text-green-600 dark:text-green-400 mb-2">✓ Configured</p>
                                @endif
                                <input type="text" name="settings[recaptcha_secret_key]" placeholder="6Le-XXXX..." autocomplete="off" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white font-mono text-sm" />
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">
                                    @if($settings['recaptcha_secret_key'] ?? false)
                                        A secret key is configured. Paste a new one to update, or leave blank to keep it.
                                    @else
                                        Private key from Google reCAPTCHA console. Keep this secure!
                                    @endif
                                </p>
                            </div>

                            <div class="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-900/50 rounded-lg p-4">
                                <div class="flex gap-3">
                                    <svg class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 5v8a2 2 0 01-2 2h-5l-5 4v-4H4a2 2 0 01-2-2V5a2 2 0 012-2h12a2 2 0 012 2zm-11-1a1 1 0 11-2 0 1 1 0 012 0zM8 8a1 1 0 000 2h6a1 1 0 000-2H8zm1 5a1 1 0 11-2 0 1 1 0 012 0z" clip-rule="evenodd"/></svg>
                                    <div class="text-sm text-blue-900 dark:text-blue-100">
                                        <p class="font-semibold">How it works:</p>
                                        <ul class="list-disc list-inside mt-2 space-y-1">
                                            <li>reCAPTCHA v3 runs invisibly in the background</li>
                                            <li>No user interaction required (no checkboxes)</li>
                                            <li>Scores users on 0-1 scale (1 = human, 0 = bot)</li>
                                            <li>Blocks registrations from detected bot traffic</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </fieldset>

                    <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex justify-between items-center">
                        <p class="text-sm text-slate-600 dark:text-slate-400 save-status" style="display:none;"></p>
                        <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Save Security Settings
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>

<script>
    function settingsCsrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.content
            || document.querySelector('input[name="_token"]')?.value;
    }

    function settingsErrorMessage(data, fallback = 'Error saving') {
        if (data?.message) {
            return data.message;
        }

        if (data?.errors) {
            return Object.values(data.errors).flat().join(' ');
        }

        return fallback;
    }

    function ensureSaveStatus(form) {
        let statusEl = form.querySelector('.save-status');
        if (statusEl) {
            return statusEl;
        }

        statusEl = document.createElement('p');
        statusEl.className = 'text-sm text-slate-600 dark:text-slate-400 save-status';
        statusEl.style.display = 'none';

        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn?.parentElement) {
            submitBtn.parentElement.insertBefore(statusEl, submitBtn);
        } else {
            form.appendChild(statusEl);
        }

        return statusEl;
    }

    window.applyZohoPreset = function applyZohoPreset(type) {
        const form = document.querySelector('div[x-show="activeTab === \'email\'"] form');
        if (! form) {
            return;
        }

        const hostInput = form.querySelector('input[name="settings[smtp_host]"]');
        const portInput = form.querySelector('input[name="settings[smtp_port]"]');
        const encryptionSelect = form.querySelector('select[name="settings[smtp_encryption]"]');

        if (! hostInput || ! portInput || ! encryptionSelect) {
            return;
        }

        hostInput.value = type === 'paid' ? 'smtppro.zoho.com' : 'smtp.zoho.com';
        portInput.value = '587';
        encryptionSelect.value = 'tls';
    }

    window.submitForm = async function submitForm(form) {
        const statusEl = ensureSaveStatus(form);
        const submitBtn = form.querySelector('button[type="submit"]');

        if (! submitBtn) {
            alert('Unable to save: submit button not found.');
            return;
        }

        const originalHTML = submitBtn.innerHTML;

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<svg class="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Saving...';

        try {
            const formData = new FormData(form);

            form.querySelectorAll('input[type="checkbox"][name^="settings["]').forEach(checkbox => {
                while (formData.has(checkbox.name)) {
                    formData.delete(checkbox.name);
                }
                formData.append(checkbox.name, checkbox.checked ? '1' : '0');
            });

            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const contentType = response.headers.get('content-type') || '';
            if (! contentType.includes('application/json')) {
                throw new Error('Unexpected response from server.');
            }

            const data = await response.json();

            if (response.ok && data.success) {
                statusEl.textContent = '✅ ' + (data.message || 'Settings saved successfully.');
                statusEl.className = 'text-sm text-green-600 dark:text-green-400 save-status';
                statusEl.style.display = 'block';
                setTimeout(() => { statusEl.style.display = 'none'; }, 3000);
            } else {
                statusEl.textContent = '❌ ' + settingsErrorMessage(data);
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
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': settingsCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (response.ok && data.success) {
                alert('✅ ' + data.message);
                document.getElementById('test_email').value = '';
            } else {
                alert('❌ ' + (data.message || 'Failed to send test email'));
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
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': settingsCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest'
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
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': settingsCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest'
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

    // SMS Templates Manager
    function smsTemplates(items) {
        const drafts = {};
        items.forEach(item => {
            drafts[item.id] = {
                body: item.body,
                recipient_type: item.recipient_type,
            };
        });

        return {
            items,
            drafts,
            expanded: {},
            smsSaving: {},
            smsStatus: {},

            recipientBadgeClass(type) {
                const map = {
                    admin: 'bg-orange-100 dark:bg-orange-900/40 text-orange-800 dark:text-orange-200',
                    both: 'bg-purple-100 dark:bg-purple-900/40 text-purple-800 dark:text-purple-200',
                    customer: 'bg-blue-100 dark:bg-blue-900/40 text-blue-800 dark:text-blue-200',
                };
                return map[type] || map.customer;
            },

            recipientLabel(type) {
                if (type === 'both') return 'Customer & admin';
                return type.charAt(0).toUpperCase() + type.slice(1);
            },

            toggle(id) {
                this.expanded[id] = !this.expanded[id];
            },

            isExpanded(id) {
                return !!this.expanded[id];
            },

            expandAll() {
                this.items.forEach(item => { this.expanded[item.id] = true; });
            },

            collapseAll() {
                this.expanded = {};
            },

            insertVariable(id, token) {
                const draft = this.drafts[id];
                if (!draft) return;
                draft.body = (draft.body || '') + token;
            },

            csrfToken() {
                return document.querySelector('meta[name="csrf-token"]')?.content
                    || document.querySelector('input[name="_token"]')?.value;
            },

            save(id) {
                const draft = this.drafts[id];
                if (!draft.body?.trim()) {
                    this.smsStatus[id] = { type: 'error', msg: 'Message cannot be empty' };
                    return;
                }
                this.saveTemplate(id, draft.body, draft.recipient_type);
            },

            async saveTemplate(templateId, body, recipientType) {
                this.smsSaving[templateId] = true;

                try {
                    const response = await fetch(`{{ route('admin.sms-templates.update', '') }}/${templateId}`, {
                        method: 'PUT',
                        body: JSON.stringify({ body, recipient_type: recipientType }),
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': this.csrfToken(),
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    const data = await response.json();
                    this.smsStatus[templateId] = response.ok && data.success
                        ? { type: 'success', msg: data.message }
                        : { type: 'error', msg: data.message || 'Error saving' };

                    if (response.ok && data.success) {
                        const item = this.items.find(i => i.id === templateId);
                        if (item) {
                            item.body = body;
                        }
                        setTimeout(() => { this.smsStatus[templateId] = null; }, 3000);
                    }
                } catch (error) {
                    this.smsStatus[templateId] = { type: 'error', msg: error.message };
                } finally {
                    this.smsSaving[templateId] = false;
                }
            },

            async resetTemplate(templateId, url) {
                if (!await window.appConfirm('Reset this SMS template to default?', 'Reset template', 'Reset')) {
                    return;
                }

                this.smsSaving[templateId] = true;

                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-Token': this.csrfToken(),
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    const data = await response.json();
                    if (response.ok && data.success) {
                        setTimeout(() => location.reload(), 800);
                    }
                    this.smsStatus[templateId] = response.ok && data.success
                        ? { type: 'success', msg: data.message }
                        : { type: 'error', msg: data.message || 'Error resetting' };
                } catch (error) {
                    this.smsStatus[templateId] = { type: 'error', msg: error.message };
                } finally {
                    this.smsSaving[templateId] = false;
                }
            }
        };
    }

    function emailTemplates(items) {
        const drafts = {};
        items.forEach(item => {
            drafts[item.id] = {
                subject: item.subject,
                body: item.body,
                enabled: item.enabled,
            };
        });

        return {
            items,
            drafts,
            expanded: {},
            emailSaving: {},
            emailStatus: {},

            recipientBadgeClass(type) {
                const map = {
                    admin: 'bg-orange-100 dark:bg-orange-900/40 text-orange-800 dark:text-orange-200',
                    reseller: 'bg-purple-100 dark:bg-purple-900/40 text-purple-800 dark:text-purple-200',
                    customer: 'bg-blue-100 dark:bg-blue-900/40 text-blue-800 dark:text-blue-200',
                };
                return map[type] || map.customer;
            },

            toggle(id) {
                this.expanded[id] = !this.expanded[id];
            },

            isExpanded(id) {
                return !!this.expanded[id];
            },

            expandAll() {
                this.items.forEach(item => { this.expanded[item.id] = true; });
            },

            collapseAll() {
                this.expanded = {};
            },

            insertVariable(id, token) {
                const draft = this.drafts[id];
                if (!draft) return;
                draft.body = (draft.body || '') + token;
            },

            csrfToken() {
                return document.querySelector('meta[name="csrf-token"]')?.content
                    || document.querySelector('input[name="_token"]')?.value;
            },

            save(id) {
                const draft = this.drafts[id];
                this.saveEmailTemplate(id, draft.subject, draft.body, draft.enabled);
            },

            async saveEmailTemplate(templateId, subject, body, enabled) {
                this.emailSaving[templateId] = true;

                try {
                    const response = await fetch(`{{ route('admin.email-templates.update', '') }}/${templateId}`, {
                        method: 'PUT',
                        body: JSON.stringify({ subject, body, enabled }),
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': this.csrfToken(),
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    const data = await response.json();
                    this.emailStatus[templateId] = response.ok && data.success
                        ? { type: 'success', msg: data.message }
                        : { type: 'error', msg: data.message || 'Error saving' };

                    if (response.ok && data.success) {
                        const item = this.items.find(i => i.id === templateId);
                        if (item) {
                            item.subject = subject;
                        }
                        setTimeout(() => { this.emailStatus[templateId] = null; }, 3000);
                    }
                } catch (error) {
                    this.emailStatus[templateId] = { type: 'error', msg: error.message };
                } finally {
                    this.emailSaving[templateId] = false;
                }
            },

            async resetTemplate(templateId, url) {
                if (!await window.appConfirm('Reset this email template to default?', 'Reset template', 'Reset')) {
                    return;
                }

                this.emailSaving[templateId] = true;

                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-Token': this.csrfToken(),
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    const data = await response.json();
                    if (response.ok && data.success) {
                        setTimeout(() => location.reload(), 800);
                    }
                    this.emailStatus[templateId] = response.ok && data.success
                        ? { type: 'success', msg: data.message }
                        : { type: 'error', msg: data.message || 'Error resetting' };
                } catch (error) {
                    this.emailStatus[templateId] = { type: 'error', msg: error.message };
                } finally {
                    this.emailSaving[templateId] = false;
                }
            }
        };
    }
</script>
@endsection

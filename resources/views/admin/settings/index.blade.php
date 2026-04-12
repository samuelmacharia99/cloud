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
                <button @click="activeTab = 'sms'" :class="activeTab === 'sms' ? 'border-b-2 border-blue-600 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors text-sm">SMS</button>
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
</script>
@endpush

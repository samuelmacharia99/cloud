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
<div class="space-y-8" x-data="settingsTabs(@js($activeSettingsTab))" x-init="init()">
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
    <div class="ui-card">
        <div class="flex border-b border-slate-200 dark:border-slate-800">
            <!-- Payment Gateways Tab -->
            <button type="button" @click="setTab('payment')" :class="activeTab === 'payment' ? 'border-b-2 border-blue-500 text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-950/30' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="flex-1 px-6 py-4 font-medium transition flex items-center justify-center gap-2">
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
            <button type="button" @click="setTab('email')" :class="activeTab === 'email' ? 'border-b-2 border-purple-500 text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-950/30' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="flex-1 px-6 py-4 font-medium transition flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                <span>Email</span>
            </button>

            <!-- Branding Tab -->
            <button type="button" @click="setTab('branding')" :class="activeTab === 'branding' ? 'border-b-2 border-amber-500 text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/30' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="flex-1 px-6 py-4 font-medium transition flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/>
                </svg>
                <span>Branding</span>
            </button>

            <!-- Hosting / DirectAdmin Tab -->
            <button type="button" @click="setTab('hosting')" :class="activeTab === 'hosting' ? 'border-b-2 border-purple-500 text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-950/30' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="flex-1 px-6 py-4 font-medium transition flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/></svg>
                <span>Hosting</span>
            </button>

            <!-- Nameservers Tab -->
            <button type="button" @click="setTab('nameservers')" :class="activeTab === 'nameservers' ? 'border-b-2 border-cyan-500 text-cyan-600 dark:text-cyan-400 bg-cyan-50 dark:bg-cyan-950/30' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="flex-1 px-6 py-4 font-medium transition flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 10-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                </svg>
                <span>Nameservers</span>
            </button>
        </div>

        <!-- Tab Content -->
        <div class="p-8">
            <!-- Payment Gateways Tab Content -->
            @include('reseller.settings.partials.payment-tab')

            <!-- SMS Tab Content -->
            @include('reseller.settings.partials.sms-tab')

            <!-- Email Tab Content -->
            @include('reseller.settings.partials.email-tab')

            <!-- Branding Tab Content -->
            @include('reseller.settings.partials.branding-tab')

            <!-- Hosting Tab Content -->
            <div x-show="activeTab === 'hosting'" x-transition class="space-y-6">
                @include('reseller.settings.partials.hosting-directadmin')
            </div>

            <!-- Nameservers Tab Content -->
            @include('reseller.settings.partials.nameservers-tab')
        </div>
    </div>
</div>

<script>
function settingsTabs(initialTab) {
    const allowed = ['payment', 'sms', 'email', 'branding', 'nameservers', 'hosting'];

    return {
        activeTab: allowed.includes(initialTab) ? initialTab : 'payment',
        setTab(tab) {
            if (! allowed.includes(tab)) {
                return;
            }
            this.activeTab = tab;
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tab);
            window.history.replaceState({}, '', url);
        },
        init() {
            const urlTab = new URL(window.location.href).searchParams.get('tab');
            if (urlTab && allowed.includes(urlTab)) {
                this.activeTab = urlTab;
            }

            if (this.activeTab === 'branding') {
                this.$nextTick(() => {
                    document.getElementById('settings-branding-panel')?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start',
                    });
                });
            }
        },
    };
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

function resellerEmailTemplates(items) {
    const drafts = {};
    items.forEach(item => {
        drafts[item.id] = {
            subject: item.subject,
            body: item.body,
            enabled: item.enabled,
            is_overridden: item.is_overridden,
        };
    });

    return {
        items,
        drafts,
        expanded: {},
        emailSaving: {},
        emailStatus: {},

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
                const response = await fetch(`{{ url('/reseller/settings/email-templates') }}/${encodeURIComponent(templateId)}`, {
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
                        item.is_overridden = true;
                    }
                    if (this.drafts[templateId]) {
                        this.drafts[templateId].is_overridden = true;
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
                if (response.ok && data.success && data.template) {
                    const item = this.items.find(i => i.id === templateId);
                    if (item) {
                        item.subject = data.template.subject;
                        item.body = data.template.body;
                        item.enabled = data.template.enabled;
                        item.is_overridden = false;
                    }
                    this.drafts[templateId] = {
                        subject: data.template.subject,
                        body: data.template.body,
                        enabled: data.template.enabled,
                        is_overridden: false,
                    };
                    this.emailStatus[templateId] = { type: 'success', msg: data.message };
                    setTimeout(() => { this.emailStatus[templateId] = null; }, 3000);
                } else {
                    this.emailStatus[templateId] = { type: 'error', msg: data.message || 'Error resetting' };
                }
            } catch (error) {
                this.emailStatus[templateId] = { type: 'error', msg: error.message };
            } finally {
                this.emailSaving[templateId] = false;
            }
        },
    };
}
</script>
@endsection

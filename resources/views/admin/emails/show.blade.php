@extends('layouts.admin')

@section('title', 'Email Details')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.emails.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Emails</a>
    <span class="text-slate-400">/</span>
    <p class="text-slate-600 dark:text-slate-400">{{ $email->subject }}</p>
</div>
@endsection

@section('content')
<div class="space-y-6" x-data="{ view: 'preview' }">
    <!-- Header -->
    <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $email->subject }}</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">How this email appeared to the customer</p>
        </div>
        <div class="flex items-center gap-2">
            @if (in_array($email->status, ['failed', 'bounced'], true))
                <form method="POST" action="{{ route('admin.emails.resend', $email) }}" class="inline"
                    onsubmit="return confirm('Resend this email to {{ $email->recipient }}?');">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-lg transition text-sm">
                        Resend Email
                    </button>
                </form>
            @endif
            <a href="{{ route('admin.emails.index') }}" class="px-4 py-2 text-slate-700 dark:text-slate-300 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                ← Back
            </a>
        </div>
    </div>

    <!-- Delivery metadata -->
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-4">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Recipient</p>
            <p class="text-base font-semibold text-slate-900 dark:text-white mt-2 break-all">{{ $email->recipient }}</p>
            @if ($recipientUser)
                <div class="mt-2 text-sm">
                    <x-admin.customer-link :user="$recipientUser" />
                </div>
            @endif
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Status</p>
            <div class="mt-2">
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $email->status === 'sent' ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : ($email->status === 'failed' ? 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300') }}">
                    {{ ucfirst($email->status) }}
                </span>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Notification</p>
            <p class="text-base font-semibold text-slate-900 dark:text-white mt-2">
                {{ $eventLabel ?? '—' }}
            </p>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Sent By</p>
            <p class="text-base font-semibold text-slate-900 dark:text-white mt-2">
                @if ($email->sentBy)
                    <x-admin.customer-link :user="$email->sentBy" />
                @else
                    <span class="text-slate-500">System</span>
                @endif
            </p>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Sent At</p>
            <p class="text-base font-semibold text-slate-900 dark:text-white mt-2">{{ $email->created_at->format('M d, Y H:i') }}</p>
        </div>
    </div>

    <!-- Customer inbox preview -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">Customer inbox preview</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-0.5">
                    Branded as <strong>{{ $branding['company_name'] }}</strong>
                    @if ($branding['is_white_label'] ?? false)
                        <span class="text-purple-600 dark:text-purple-400">(reseller white-label)</span>
                    @endif
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" @click="view = 'preview'"
                    :class="view === 'preview' ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300'"
                    class="px-3 py-1.5 rounded-lg text-sm font-medium transition">
                    Rendered email
                </button>
                <button type="button" @click="view = 'plain'"
                    :class="view === 'plain' ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300'"
                    class="px-3 py-1.5 rounded-lg text-sm font-medium transition">
                    Plain text
                </button>
            </div>
        </div>

        <!-- Mock inbox header -->
        <div class="px-6 py-5 bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-800">
            <div class="max-w-3xl space-y-2 text-sm">
                <div class="flex gap-3">
                    <span class="w-16 shrink-0 text-slate-500 dark:text-slate-400 font-medium">From</span>
                    <span class="text-slate-900 dark:text-white">
                        {{ $fromName }}
                        <span class="text-slate-500 dark:text-slate-400">&lt;{{ $fromAddress }}&gt;</span>
                    </span>
                </div>
                <div class="flex gap-3">
                    <span class="w-16 shrink-0 text-slate-500 dark:text-slate-400 font-medium">To</span>
                    <span class="text-slate-900 dark:text-white break-all">{{ $email->recipient }}</span>
                </div>
                <div class="flex gap-3">
                    <span class="w-16 shrink-0 text-slate-500 dark:text-slate-400 font-medium">Subject</span>
                    <span class="text-slate-900 dark:text-white font-medium">{{ $email->subject }}</span>
                </div>
                <div class="flex gap-3">
                    <span class="w-16 shrink-0 text-slate-500 dark:text-slate-400 font-medium">Date</span>
                    <span class="text-slate-700 dark:text-slate-300">{{ $email->created_at->format('D, M j, Y \a\t g:i A') }}</span>
                </div>
            </div>
        </div>

        <div class="p-6">
            <div x-show="view === 'preview'">
                @if ($customerHtml)
                    <div class="rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden bg-[#f3f4f6]">
                        <iframe
                            title="Customer email preview"
                            class="w-full min-h-[520px] bg-white"
                            sandbox="allow-same-origin"
                            srcdoc="{{ e($customerHtml) }}"
                        ></iframe>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-3">
                        Preview uses the same email layout and branding the customer receives.
                        @if (empty($email->html_body))
                            Reconstructed from logged message text.
                        @endif
                    </p>
                @else
                    <div class="rounded-lg border border-dashed border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800/40 p-10 text-center">
                        <p class="text-slate-700 dark:text-slate-300 font-medium">No preview available</p>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mt-2 max-w-lg mx-auto">
                            This email was logged before HTML previews were stored, and no message text was saved.
                            New emails will include a full customer preview automatically.
                        </p>
                    </div>
                @endif
            </div>

            <div x-show="view === 'plain'" x-cloak>
                @if (filled($plainTextContent))
                    <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-6 text-slate-700 dark:text-slate-300 whitespace-pre-wrap break-words text-sm leading-relaxed max-h-[32rem] overflow-y-auto border border-slate-200 dark:border-slate-700">
                        {{ $plainTextContent }}
                    </div>
                @else
                    <div class="rounded-lg border border-dashed border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800/40 p-10 text-center">
                        <p class="text-slate-700 dark:text-slate-300 font-medium">No message content logged</p>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mt-2">The plain-text body for this email was not stored.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Raw logged body (admin debug) -->
    @if (filled($email->body) && $email->body !== $plainTextContent)
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-2">Logged message text</h2>
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Original text stored when the email was sent (before HTML wrapping).</p>
            <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-6 text-slate-700 dark:text-slate-300 whitespace-pre-wrap break-words text-sm leading-relaxed max-h-64 overflow-y-auto border border-slate-200 dark:border-slate-700">
                {{ $email->body }}
            </div>
        </div>
    @endif

    @if ($email->status !== 'sent' && $email->response)
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Error Response</h2>
            <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-6 text-red-700 dark:text-red-300 font-mono text-sm overflow-x-auto border border-red-200 dark:border-red-800 max-h-64 overflow-y-auto">
                {{ $email->response }}
            </div>
        </div>
    @endif
</div>
@endsection

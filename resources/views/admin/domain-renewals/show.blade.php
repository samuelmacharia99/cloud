@extends('layouts.admin')

@section('title', $renewal->domain->name . $renewal->domain->extension . ' - Renewal')

@section('breadcrumb')
<div class="flex items-center gap-2">
    <a href="{{ route('admin.domain-renewals.index') }}" class="text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
        Domain Renewals
    </a>
    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
    </svg>
    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">{{ $renewal->domain->name }}{{ $renewal->domain->extension }}</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $renewal->domain->name }}{{ $renewal->domain->extension }}</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Renewal Order #{{ $renewal->id }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Renewal Details -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Renewal Details</h2>

                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-slate-600 dark:text-slate-400">Domain</p>
                            <p class="font-semibold text-slate-900 dark:text-white">{{ $renewal->domain->name }}{{ $renewal->domain->extension }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-slate-600 dark:text-slate-400">Years</p>
                            <p class="font-semibold text-slate-900 dark:text-white">{{ $renewal->years }} year{{ $renewal->years > 1 ? 's' : '' }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-slate-600 dark:text-slate-400">Amount</p>
                            <p class="font-semibold text-slate-900 dark:text-white">KES {{ number_format($renewal->amount, 2) }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-slate-600 dark:text-slate-400">Status</p>
                            <p class="font-semibold">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium {{ match($renewal->status) {
                                    'pending' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300',
                                    'invoiced' => 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
                                    'paid' => 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300',
                                    'pushed' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300',
                                    'completed' => 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
                                    'failed' => 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300',
                                    'expired' => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
                                    default => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
                                } }}">
                                    {{ ucfirst($renewal->status) }}
                                </span>
                            </p>
                        </div>
                    </div>

                    <div class="border-t border-slate-200 dark:border-slate-700 pt-4 mt-4">
                        <h3 class="font-semibold text-slate-900 dark:text-white mb-3">Timeline</h3>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-slate-600 dark:text-slate-400">Created</span>
                                <span class="font-medium text-slate-900 dark:text-white">{{ $renewal->created_at->format('M d, Y H:i') }}</span>
                            </div>
                            @if($renewal->invoiced_at)
                                <div class="flex justify-between">
                                    <span class="text-slate-600 dark:text-slate-400">Invoiced</span>
                                    <span class="font-medium text-slate-900 dark:text-white">{{ $renewal->invoiced_at->format('M d, Y H:i') }}</span>
                                </div>
                            @endif
                            @if($renewal->pushed_at)
                                <div class="flex justify-between">
                                    <span class="text-slate-600 dark:text-slate-400">Pushed to Admin</span>
                                    <span class="font-medium text-slate-900 dark:text-white">{{ $renewal->pushed_at->format('M d, Y H:i') }}</span>
                                </div>
                            @endif
                            @if($renewal->completed_at)
                                <div class="flex justify-between">
                                    <span class="text-slate-600 dark:text-slate-400">Completed</span>
                                    <span class="font-medium text-slate-900 dark:text-white">{{ $renewal->completed_at->format('M d, Y H:i') }}</span>
                                </div>
                            @endif
                        </div>
                    </div>

                    @if($renewal->failure_reason)
                        <div class="border-t border-slate-200 dark:border-slate-700 pt-4 mt-4">
                            <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Failure Reason</p>
                            <p class="text-slate-900 dark:text-white">{{ $renewal->failure_reason }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Customer Info -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Billing Account</h2>

                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-slate-600 dark:text-slate-400">Name</span>
                        <x-admin.customer-link :user="$renewal->user" class="text-slate-900 dark:text-white" />
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-600 dark:text-slate-400">Email</span>
                        <span class="font-medium text-slate-900 dark:text-white">{{ $renewal->user->email }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-600 dark:text-slate-400">Phone</span>
                        <span class="font-medium text-slate-900 dark:text-white">{{ $renewal->user->phone ?? 'N/A' }}</span>
                    </div>
                </div>
            </div>

            <!-- Action Forms -->
            @if(in_array($renewal->status, ['pushed', 'failed'], true))
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 space-y-6">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-1">Mark as renewed (manual)</h2>
                        <p class="text-sm text-slate-600 dark:text-slate-400">
                            Use this after renewing at the registrar outside the API. Expiry updates everywhere this domain is shown.
                        </p>
                    </div>

                    <form action="{{ route('admin.domain-renewals.complete-manually', $renewal) }}" method="POST" class="space-y-4">
                        @csrf
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Renewal duration</label>
                                <select name="years" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                                    @foreach($availableYears as $year)
                                        <option value="{{ $year }}" @selected(old('years', $renewal->years) == $year)>
                                            {{ $year }} year{{ $year > 1 ? 's' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Projected new expiry</label>
                                <p class="px-4 py-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 text-sm font-medium text-slate-900 dark:text-white">
                                    {{ $renewalService->projectedExpiryAfterRenewal($renewal->domain, (int) old('years', $renewal->years))->format('M d, Y') }}
                                </p>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Admin notes (optional)</label>
                            <textarea name="admin_notes" rows="3" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" placeholder="Registrar reference, manual renewal details...">{{ old('admin_notes') }}</textarea>
                        </div>

                        <label class="flex items-start gap-3 text-sm text-slate-700 dark:text-slate-300">
                            <input type="hidden" name="send_notification" value="0">
                            <input type="checkbox" name="send_notification" value="1" @checked(old('send_notification', '1') == '1') class="mt-1 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span>
                                Email notification to
                                @if($notificationRecipient)
                                    <strong>{{ $notificationRecipient->name }}</strong> ({{ $notificationRecipient->email }})
                                @else
                                    the account owner
                                @endif
                                @if($renewal->domain->user && $renewal->domain->user->id !== ($notificationRecipient?->id))
                                    <span class="block text-xs text-slate-500 dark:text-slate-400 mt-1">End customers are not emailed — only the reseller or direct account owner.</span>
                                @endif
                            </span>
                        </label>

                        <button type="submit" class="w-full px-4 py-2.5 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition">
                            Mark as renewed
                        </button>
                    </form>

                    <div class="border-t border-slate-200 dark:border-slate-700 pt-6">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Or retry via registrar API</h3>
                        <form action="{{ route('admin.domain-renewals.complete', $renewal) }}" method="POST" class="space-y-3">
                            @csrf
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Admin notes (optional)</label>
                                <textarea name="admin_notes" rows="2" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white"></textarea>
                            </div>
                            <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                                Renew via API
                            </button>
                        </form>

                        <form action="{{ route('admin.domain-renewals.fail', $renewal) }}" method="POST" class="space-y-3 mt-4">
                            @csrf
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Failure reason</label>
                                <textarea name="failure_reason" rows="2" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" required></textarea>
                            </div>
                            <button type="submit" class="w-full px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition">
                                Mark as failed
                            </button>
                        </form>
                    </div>
                </div>
            @endif
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Related Invoices -->
            @if($renewal->invoice || $renewal->adminInvoice)
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                    <h3 class="font-bold text-slate-900 dark:text-white mb-4">Related Invoices</h3>

                    <div class="space-y-3">
                        @if($renewal->invoice)
                            <div>
                                <p class="text-sm text-slate-600 dark:text-slate-400 mb-1">Customer Invoice</p>
                                <a href="{{ route('admin.invoices.show', $renewal->invoice) }}" class="text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 font-medium">
                                    INV-{{ $renewal->invoice->id }}
                                </a>
                            </div>
                        @endif

                        @if($renewal->adminInvoice)
                            <div>
                                <p class="text-sm text-slate-600 dark:text-slate-400 mb-1">Admin Invoice</p>
                                <a href="{{ route('admin.invoices.show', $renewal->adminInvoice) }}" class="text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 font-medium">
                                    INV-{{ $renewal->adminInvoice->id }}
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            <!-- Domain Info -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="font-bold text-slate-900 dark:text-white mb-4">Domain Information</h3>

                <div class="space-y-3 text-sm">
                    <div>
                        <p class="text-slate-600 dark:text-slate-400 mb-1">Current Expiry</p>
                        <p class="font-medium text-slate-900 dark:text-white">{{ $renewal->domain->expires_at?->format('M d, Y') ?? 'N/A' }}</p>
                    </div>
                    @if($renewal->status === 'completed')
                        <div>
                            <p class="text-slate-600 dark:text-slate-400 mb-1">Renewed for</p>
                            <p class="font-medium text-slate-900 dark:text-white">{{ $renewal->years }} year{{ $renewal->years > 1 ? 's' : '' }}</p>
                        </div>
                    @endif
                    @if($renewal->domain->user && $renewal->domain->user->id !== $renewal->user_id)
                        <div>
                            <p class="text-slate-600 dark:text-slate-400 mb-1">Domain owner</p>
                            <p class="font-medium text-slate-900 dark:text-white">{{ $renewal->domain->user->name }}</p>
                        </div>
                    @endif
                    <div>
                        <p class="text-slate-600 dark:text-slate-400 mb-1">Status</p>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium {{ $renewal->domain->status === 'active' ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300' }}">
                            {{ ucfirst($renewal->domain->status) }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6">
                <h3 class="font-bold text-blue-900 dark:text-blue-200 mb-4">Info</h3>
                <p class="text-sm text-blue-800 dark:text-blue-300">
                    After the customer pays the invoice, the renewal order will automatically be pushed to the admin panel for processing.
                </p>
            </div>
        </div>
    </div>
</div>
@endsection

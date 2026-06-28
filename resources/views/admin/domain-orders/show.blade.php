@extends('layouts.admin')

@section('title', 'Domain Order - ' . $order->domain_name)

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $order->fullDomainName() }}</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Order #{{ $order->id }}</p>
        </div>
        <span class="inline-flex items-center px-4 py-2 rounded-full text-lg font-medium {{ match($order->status) {
            'queued' => 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300',
            'pushed' => 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300',
            'completed' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300',
            'failed' => 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300',
            'cancelled' => 'bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200',
            'expired' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
            default => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
        } }}">
            {{ $order->statusDisplayLabel() }}
        </span>
    </div>

    <!-- Details Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Reseller / channel Card -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <h3 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-4">{{ $order->isPlatformOrder() ? 'Channel' : 'Reseller' }}</h3>
            <div class="space-y-3">
                @if ($order->isPlatformOrder())
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400">Type</p>
                        <p class="font-semibold text-slate-900 dark:text-white">Platform direct customer</p>
                    </div>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Customer paid Talksasa directly. No reseller wallet or wholesale invoice applies.</p>
                @else
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400">Name</p>
                        <p class="font-semibold text-slate-900 dark:text-white">{{ $order->reseller->name }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400">Email</p>
                        <p class="font-semibold text-slate-900 dark:text-white">{{ $order->reseller->email }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400">Phone</p>
                        <p class="font-semibold text-slate-900 dark:text-white">{{ $order->reseller->phone ?? 'N/A' }}</p>
                    </div>
                @endif
            </div>
        </div>

        <!-- Customer Card -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <h3 class="text-sm font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-4">Customer</h3>
            <div class="space-y-3">
                <div>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Name</p>
                    <x-admin.customer-link :user="$order->customer" class="font-semibold text-slate-900 dark:text-white" />
                </div>
                <div>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Email</p>
                    <p class="font-semibold text-slate-900 dark:text-white">{{ $order->customer->email }}</p>
                </div>
                <div>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Phone</p>
                    <p class="font-semibold text-slate-900 dark:text-white">{{ $order->customer->phone ?? 'N/A' }}</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Order Details -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Order Details</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <p class="text-sm text-slate-600 dark:text-slate-400">Order type</p>
                <p class="font-semibold text-slate-900 dark:text-white">{{ $order->order_type?->label() ?? 'Registration' }}</p>
            </div>
            <div>
                <p class="text-sm text-slate-600 dark:text-slate-400">Domain</p>
                <p class="font-semibold text-slate-900 dark:text-white text-lg">{{ $order->fullDomainName() }}</p>
            </div>
            <div>
                <p class="text-sm text-slate-600 dark:text-slate-400">Duration</p>
                <p class="font-semibold text-slate-900 dark:text-white text-lg">{{ $order->years }} Year(s)</p>
            </div>
            <div>
                <p class="text-sm text-slate-600 dark:text-slate-400">{{ $order->isPlatformOrder() ? 'Registration cost' : 'Wholesale Amount' }}</p>
                <p class="font-semibold text-slate-900 dark:text-white text-lg">KES {{ number_format($order->wholesale_amount, 2) }}</p>
            </div>
        </div>

        @unless($order->isPlatformOrder())
        <div class="mt-6 pt-6 border-t border-slate-200 dark:border-slate-800 grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <p class="text-sm text-slate-600 dark:text-slate-400">Retail Amount</p>
                <p class="font-semibold text-slate-900 dark:text-white text-lg">KES {{ number_format($order->retail_amount, 2) }}</p>
            </div>
            <div>
                <p class="text-sm text-slate-600 dark:text-slate-400">Profit Margin</p>
                <p class="font-semibold text-emerald-600 dark:text-emerald-400 text-lg">KES {{ number_format($order->retail_amount - $order->wholesale_amount, 2) }}</p>
            </div>
            <div>
                <p class="text-sm text-slate-600 dark:text-slate-400">Push Mode</p>
                <p class="font-semibold text-slate-900 dark:text-white text-lg">{{ ucfirst($order->push_mode) }}</p>
            </div>
            @if($paidInvoice = $order->paidWholesaleInvoice())
            <div>
                <p class="text-sm text-slate-600 dark:text-slate-400">Wholesale Payment</p>
                <p class="font-semibold text-emerald-600 dark:text-emerald-400 text-lg">
                    Paid — {{ $paidInvoice->invoice_number }}
                </p>
            </div>
            @endif
        </div>
        @else
        <div class="mt-6 pt-6 border-t border-slate-200 dark:border-slate-800 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <p class="text-sm text-slate-600 dark:text-slate-400">Customer paid</p>
                <p class="font-semibold text-slate-900 dark:text-white text-lg">KES {{ number_format($order->displayAmount(), 2) }}</p>
            </div>
            @if($paidCustomerInvoice = $order->paidCustomerInvoice())
            <div>
                <p class="text-sm text-slate-600 dark:text-slate-400">Payment invoice</p>
                <p class="font-semibold text-emerald-600 dark:text-emerald-400 text-lg">{{ $paidCustomerInvoice->invoice_number }}</p>
            </div>
            @endif
        </div>
        @endunless
    </div>

    @if($order->isTransfer())
    @php
        $transferDomain = $order->domain;
        $authCode = $transferDomain?->epp_code ?: $transferDomain?->transfer_authorization_code;
        $canEditTransfer = $order->canAdminEditTransferDetails();
    @endphp
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-blue-200 dark:border-blue-800 p-6" x-data="{ copied: false, editing: @js($canEditTransfer && $errors->hasAny(['epp_code', 'old_registrar', 'old_registrar_url', 'transfer_notes'])) }">
        <div class="flex items-start justify-between gap-4 mb-4">
            <div>
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Transfer Details</h3>
                @if($canEditTransfer)
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Correct the EPP code or registrar info before pushing to the registrar.</p>
                @endif
            </div>
            @if($canEditTransfer)
                <button type="button"
                    @click="editing = !editing"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition shrink-0">
                    <span x-show="!editing">Edit</span>
                    <span x-show="editing" x-cloak>Cancel</span>
                </button>
            @endif
        </div>
        @if($transferDomain)
            <div x-show="!editing">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <p class="text-sm text-slate-600 dark:text-slate-400 mb-1">EPP / auth code</p>
                        @if(filled($authCode))
                            <div class="flex flex-wrap items-center gap-2">
                                <code class="px-3 py-2 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm font-mono text-slate-900 dark:text-white break-all">{{ $authCode }}</code>
                                <button type="button"
                                    @click="navigator.clipboard.writeText(@js((string) $authCode)); copied = true; setTimeout(() => copied = false, 2000)"
                                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                                    <span x-show="!copied">Copy</span>
                                    <span x-show="copied" x-cloak>Copied</span>
                                </button>
                            </div>
                        @else
                            <p class="text-sm text-amber-700 dark:text-amber-300">No EPP code on file for this domain record.</p>
                        @endif
                    </div>
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400">Transfer status</p>
                        <p class="font-semibold text-slate-900 dark:text-white capitalize">{{ $transferDomain->transfer_status ?? 'pending' }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400">Current registrar</p>
                        <p class="font-semibold text-slate-900 dark:text-white">{{ $transferDomain->old_registrar ?? '—' }}</p>
                    </div>
                    @if($transferDomain->old_registrar_url)
                    <div class="md:col-span-2">
                        <p class="text-sm text-slate-600 dark:text-slate-400">Registrar website</p>
                        <a href="{{ $transferDomain->old_registrar_url }}" target="_blank" rel="noopener noreferrer" class="font-medium text-blue-600 dark:text-blue-400 hover:underline break-all">{{ $transferDomain->old_registrar_url }}</a>
                    </div>
                    @endif
                    @if($transferDomain->transfer_notes)
                    <div class="md:col-span-2">
                        <p class="text-sm text-slate-600 dark:text-slate-400">Notes</p>
                        <p class="text-sm text-slate-700 dark:text-slate-300 whitespace-pre-wrap">{{ $transferDomain->transfer_notes }}</p>
                    </div>
                    @endif
                </div>
            </div>

            @if($canEditTransfer)
            <form x-show="editing" x-cloak method="POST" action="{{ route('admin.domain-orders.transfer-details.update', $order) }}" class="space-y-4">
                @csrf
                @method('PATCH')
                <input type="hidden" name="stay_on_detail" value="1">
                <div>
                    <label for="epp_code" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">EPP / auth code</label>
                    <input type="text" id="epp_code" name="epp_code" value="{{ old('epp_code', $transferDomain->epp_code ?: $transferDomain->transfer_authorization_code) }}" required
                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm font-mono">
                    @error('epp_code')
                        <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="old_registrar" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Current registrar</label>
                        <input type="text" id="old_registrar" name="old_registrar" value="{{ old('old_registrar', $transferDomain->old_registrar) }}" required
                            placeholder="e.g. GoDaddy, Namecheap"
                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                        @error('old_registrar')
                            <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="old_registrar_url" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Registrar website</label>
                        <input type="url" id="old_registrar_url" name="old_registrar_url" value="{{ old('old_registrar_url', $transferDomain->old_registrar_url) }}"
                            placeholder="https://example.com"
                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                        @error('old_registrar_url')
                            <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
                <div>
                    <label for="transfer_notes" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Notes</label>
                    <textarea id="transfer_notes" name="transfer_notes" rows="3"
                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">{{ old('transfer_notes', $transferDomain->transfer_notes) }}</textarea>
                    @error('transfer_notes')
                        <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition">
                        Save transfer details
                    </button>
                    <button type="button" @click="editing = false" class="inline-flex items-center gap-2 px-4 py-2 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-900 dark:text-white text-sm font-medium rounded-lg transition">
                        Cancel
                    </button>
                </div>
            </form>
            @endif
        @else
            <p class="text-sm text-amber-700 dark:text-amber-300">No domain record is linked to this transfer order.</p>
        @endif
    </div>
    @endif

    <!-- Timeline -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Timeline</h3>
        <div class="space-y-4">
            <div class="flex gap-4">
                <div class="text-sm font-semibold text-slate-600 dark:text-slate-400 w-24">Created</div>
                <div class="text-sm text-slate-900 dark:text-white">{{ $order->created_at->format('M d, Y H:i') }}</div>
            </div>
            @if($order->queued_at)
            <div class="flex gap-4">
                <div class="text-sm font-semibold text-slate-600 dark:text-slate-400 w-24">Queued</div>
                <div class="text-sm text-slate-900 dark:text-white">{{ $order->queued_at->format('M d, Y H:i') }}</div>
            </div>
            @endif
            @if($order->pushed_at)
            <div class="flex gap-4">
                <div class="text-sm font-semibold text-slate-600 dark:text-slate-400 w-24">{{ $order->timelinePushedLabel() }}</div>
                <div class="text-sm text-slate-900 dark:text-white">{{ $order->pushed_at->format('M d, Y H:i') }}</div>
            </div>
            @endif
            @if($order->completed_at)
            <div class="flex gap-4">
                <div class="text-sm font-semibold text-slate-600 dark:text-slate-400 w-24">Completed</div>
                <div class="text-sm text-slate-900 dark:text-white">{{ $order->completed_at->format('M d, Y H:i') }}</div>
            </div>
            @endif
            @if($order->failed_at)
            <div class="flex gap-4">
                <div class="text-sm font-semibold text-slate-600 dark:text-slate-400 w-24">Failed</div>
                <div class="text-sm text-slate-900 dark:text-white">{{ $order->failed_at->format('M d, Y H:i') }}</div>
            </div>
            @endif
            @if($order->cancelled_at)
            <div class="flex gap-4">
                <div class="text-sm font-semibold text-slate-600 dark:text-slate-400 w-24">Cancelled</div>
                <div class="text-sm text-slate-900 dark:text-white">{{ $order->cancelled_at->format('M d, Y H:i') }}</div>
            </div>
            @endif
            @if($order->failure_reason)
            <div class="flex gap-4">
                <div class="text-sm font-semibold text-slate-600 dark:text-slate-400 w-24">Note</div>
                <div class="text-sm text-slate-900 dark:text-white">{{ $order->failure_reason }}</div>
            </div>
            @endif
        </div>
    </div>

    @if($order->status === 'queued')
    <div class="bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 rounded-2xl p-4 text-sm text-amber-900 dark:text-amber-200">
        @if($order->isPlatformOrder())
            @if($order->hasPaidCustomerInvoice())
                <strong>Customer paid</strong> — registration at Openprovider runs automatically after payment.
            @else
                <strong>Queued</strong> — waiting for the customer to pay their domain invoice.
            @endif
        @elseif($order->hasPaidWholesaleInvoice())
            <strong>Queued</strong> — wholesale invoice is <strong>paid</strong> (M-Pesa/card/bank, not wallet). You can <strong>Complete</strong> directly or use <strong>Push to admin</strong> first; no wallet debit is required.
        @else
            <strong>Queued</strong> — waiting for reseller wallet funds or wholesale payment. Use <strong>Push to admin</strong> when ready (wallet debit) or after the reseller pays their invoice.
        @endif
    </div>
    @endif

    @if($order->status === 'pushed' || $order->status === 'failed')
    <div class="bg-violet-50 dark:bg-violet-950/40 border border-violet-200 dark:border-violet-800 rounded-2xl p-4 text-sm text-violet-900 dark:text-violet-200">
        @if($order->status === 'failed')
            <strong>Registrar submission failed</strong> — {{ $order->failure_reason ?? 'See logs for details.' }} Top up Openprovider balance, verify contact handles and nameservers, then use <strong>Push to registrar</strong> to retry.
        @elseif($order->hasPendingRegistrarSubmission())
            <strong>Submitted to Openprovider</strong> — awaiting registry activation. The order will complete automatically when the domain becomes active (sync cron). No admin action needed unless this stalls.
        @else
            <strong>Processing</strong> — wholesale payment is confirmed. Registration at Openprovider runs automatically; use <strong>Push to registrar</strong> only if automatic submission failed.
        @endif
    </div>
    @endif

    @if($order->canAdminPush() || $order->canAdminPushToRegistrar() || $order->canCancel() || $order->canAdminDelete())
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Quick Actions</h3>
        <div class="flex flex-wrap items-center gap-2">
            @if($order->canAdminPush())
            <form method="POST" action="{{ route('admin.domain-orders.push', $order) }}" data-confirm="{{ $order->adminPrepareConfirmMessage() }}">
                @csrf
                <input type="hidden" name="stay_on_detail" value="1">
                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition" title="{{ $order->adminPrepareButtonTitle() }}">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                    {{ $order->adminPrepareButtonLabel() }}
                </button>
            </form>
            @endif
            @if($order->canAdminPushToRegistrar())
            <form method="POST" action="{{ route('admin.domain-orders.push-registrar', $order) }}" data-confirm="Submit this domain to the API registrar now?@if($order->status === 'failed') This retries a failed automatic submission.@endif">
                @csrf
                <input type="hidden" name="stay_on_detail" value="1">
                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white text-sm font-medium rounded-lg transition" title="Retry registration at Openprovider after a failed automatic submission">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                    Retry at registrar
                </button>
            </form>
            @endif
            @if($order->canCancel())
            <form method="POST" action="{{ route('admin.domain-orders.cancel', $order) }}" data-confirm="Cancel this domain order?">
                @csrf
                <input type="hidden" name="stay_on_detail" value="1">
                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 bg-slate-600 hover:bg-slate-700 text-white text-sm font-medium rounded-lg transition" title="Cancel order">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                    Cancel
                </button>
            </form>
            @endif
            @if($order->canAdminDelete())
            <form method="POST" action="{{ route('admin.domain-orders.destroy', $order) }}" data-confirm="Delete this domain order permanently?">
                @csrf
                @method('DELETE')
                <input type="hidden" name="stay_on_detail" value="1">
                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition" title="Delete order">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    Delete
                </button>
            </form>
            @endif
        </div>
    </div>
    @endif

    <!-- Actions -->
    @if($order->canAdminComplete())
    <!-- Complete Order Form -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-2">Complete Domain Order</h3>
        <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Record a manual registration or transfer (e.g. no API registrar configured). Does not submit to Openprovider.</p>
        <form method="POST" action="{{ route('admin.domain-orders.complete', $order) }}" class="space-y-4">
            @csrf
            <input type="hidden" name="stay_on_detail" value="1">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Registrar</label>
                <input type="text" name="registrar" required placeholder="e.g., GoDaddy, Namecheap" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                @error('registrar')
                    <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-lg transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Mark as Completed
            </button>
        </form>
    </div>

    <!-- Fail Order -->
    <div>
        <button type="button" onclick="document.getElementById('failForm').style.display = document.getElementById('failForm').style.display === 'none' ? 'block' : 'none'" class="w-full inline-flex items-center justify-center gap-2 px-6 py-3 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Mark as Failed
        </button>
    </div>

    <!-- Fail Form (Hidden) -->
    <div id="failForm" class="bg-white dark:bg-slate-900 rounded-2xl border border-red-200 dark:border-red-800 p-6" style="display: none;">
        <h3 class="text-lg font-semibold text-red-900 dark:text-red-200 mb-4">Mark Order as Failed</h3>
        <form method="POST" action="{{ route('admin.domain-orders.fail', $order) }}">
            @csrf
            <input type="hidden" name="stay_on_detail" value="1">
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Failure Reason</label>
                <textarea name="failure_reason" required class="w-full px-4 py-2 border border-red-300 dark:border-red-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-red-500 dark:focus:ring-red-400" rows="3" placeholder="Explain why this order is being marked as failed..."></textarea>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-6 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition">Confirm Failure</button>
                <button type="button" onclick="document.getElementById('failForm').style.display = 'none'" class="px-6 py-2 bg-slate-300 dark:bg-slate-700 text-slate-900 dark:text-white font-medium rounded-lg transition">Cancel</button>
            </div>
        </form>
    </div>
    @endif
</div>
@endsection

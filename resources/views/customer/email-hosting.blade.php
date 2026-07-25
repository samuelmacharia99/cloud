@extends('layouts.customer')

@section('title', 'Email Hosting')

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between gap-4 flex-wrap">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Email Hosting</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1 max-w-2xl">
                Pick a mailbox plan, add it to your cart, and choose the mail domain at checkout.
                You can bundle email with application hosting or a domain in the same order.
                If that domain’s DNS is managed here, MX, SPF, DKIM, and DMARC are applied automatically after payment.
            </p>
        </div>
        <a href="{{ route('customer.cart.index') }}" class="relative shrink-0">
            <svg class="w-6 h-6 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
            @if($cartCount > 0)
                <span class="absolute -top-2 -right-2 bg-red-600 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center">{{ $cartCount }}</span>
            @endif
        </a>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @forelse($products as $product)
            @php
                $limits = is_array($product->resource_limits) ? $product->resource_limits : [];
                $mailboxes = $limits['mailboxes'] ?? null;
                $aliases = $limits['aliases'] ?? null;
                $quotaGb = isset($limits['quota_mb'])
                    ? rtrim(rtrim(number_format(((float) $limits['quota_mb']) / 1024, 2), '0'), '.')
                    : null;
                $mailboxQuotaGb = isset($limits['mailbox_quota_mb'])
                    ? rtrim(rtrim(number_format(((float) $limits['mailbox_quota_mb']) / 1024, 2), '0'), '.')
                    : null;
            @endphp
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 hover:border-teal-300 dark:hover:border-teal-700 transition">
                <div class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-teal-100 dark:bg-teal-900 text-teal-800 dark:text-teal-200 mb-3">
                    Email Hosting
                </div>

                <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-2">{{ $product->name }}</h3>

                @if($product->description)
                    <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">{{ Str::limit($product->description, 120) }}</p>
                @endif

                <div class="mb-4">
                    <div class="text-2xl font-bold text-slate-900 dark:text-white">
                        Ksh {{ number_format($product->monthly_price, 0) }}
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">per month</p>
                    @if($product->yearly_price)
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                            or Ksh {{ number_format($product->yearly_price, 0) }} / year
                        </p>
                    @endif
                </div>

                <ul class="space-y-2 mb-6">
                    @if($mailboxes)
                        <li class="text-sm text-slate-600 dark:text-slate-400 flex items-center gap-2">
                            <svg class="w-4 h-4 text-teal-600 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                            {{ $mailboxes }} mailboxes
                        </li>
                    @endif
                    @if($aliases !== null)
                        <li class="text-sm text-slate-600 dark:text-slate-400 flex items-center gap-2">
                            <svg class="w-4 h-4 text-teal-600 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                            {{ $aliases }} aliases
                        </li>
                    @endif
                    @if($quotaGb)
                        <li class="text-sm text-slate-600 dark:text-slate-400 flex items-center gap-2">
                            <svg class="w-4 h-4 text-teal-600 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                            {{ $quotaGb }} GB total storage
                        </li>
                    @endif
                    @if($mailboxQuotaGb)
                        <li class="text-sm text-slate-600 dark:text-slate-400 flex items-center gap-2">
                            <svg class="w-4 h-4 text-teal-600 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                            Up to {{ $mailboxQuotaGb }} GB per mailbox
                        </li>
                    @endif
                    @if($product->features && count($product->features) > 0)
                        @foreach($product->features as $feature)
                            <li class="text-sm text-slate-600 dark:text-slate-400 flex items-center gap-2">
                                <svg class="w-4 h-4 text-teal-600 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                {{ $feature }}
                            </li>
                        @endforeach
                    @endif
                </ul>

                <form action="{{ route('customer.cart.add') }}" method="POST" class="space-y-3" x-data="{ cycle: 'monthly' }">
                    @csrf
                    <input type="hidden" name="type" value="product">
                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                    <input type="hidden" name="billing_cycle" x-bind:value="cycle">

                    <div class="flex gap-2">
                        <select x-model="cycle" class="flex-1 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm">
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="semi-annual">Semi-Annual</option>
                            <option value="annual">Annual</option>
                        </select>

                        <button type="submit" class="flex-1 px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg font-medium text-sm transition">
                            Add to Cart
                        </button>
                    </div>
                </form>
            </div>
        @empty
            <div class="col-span-full text-center py-12 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800">
                <p class="text-slate-500 dark:text-slate-400">No email hosting plans are available yet.</p>
                <p class="text-sm text-slate-400 dark:text-slate-500 mt-2">Ask an administrator to create an Email Hosting product.</p>
            </div>
        @endforelse
    </div>

    <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 px-6 py-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <p class="text-sm font-medium text-slate-900 dark:text-white">Need application hosting too?</p>
            <p class="text-sm text-slate-600 dark:text-slate-400 mt-0.5">Deploy your site separately, then keep this email plan in the same cart.</p>
        </div>
        <div class="flex flex-wrap gap-3 shrink-0">
            <a href="{{ route('customer.select-techstack') }}" class="inline-flex items-center px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium transition">
                Application Hosting
            </a>
            <a href="{{ route('customer.browse-services', ['type' => 'email_hosting']) }}" class="inline-flex items-center px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-800 text-sm font-medium transition">
                See all products
            </a>
        </div>
    </div>
</div>
@endsection

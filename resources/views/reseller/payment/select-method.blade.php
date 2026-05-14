@extends('layouts.reseller')

@section('title', 'Select Payment Method')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('reseller.invoices.show', $invoice) }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Invoice</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Payment Method</p>
</div>
@endsection

@section('content')
<div class="space-y-6 max-w-2xl">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Select Payment Method</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Invoice #{{ $invoice->invoice_number }} - KES {{ number_format($invoice->total, 2) }}</p>
    </div>

    <form method="POST" action="{{ route('reseller.payment.initiate', $invoice) }}" class="space-y-6">
        @csrf

        <div class="space-y-4">
            @forelse($gateways as $key => $gateway)
                @php
                    $isSelected = old('method') === $key;
                @endphp
                <label class="relative flex items-start p-4 border-2 rounded-lg cursor-pointer transition {{ $isSelected ? 'border-purple-500 bg-purple-50 dark:bg-purple-950' : 'border-slate-200 dark:border-slate-700 hover:border-purple-300' }}">
                    <input type="radio" name="method" value="{{ $key }}" required {{ $isSelected ? 'checked' : '' }} class="w-5 h-5 mt-1 rounded-full border-slate-300 text-purple-600 focus:ring-0 focus:border-purple-500 transition">

                    <div class="ml-4 flex-1">
                        <p class="font-semibold text-slate-900 dark:text-white">{{ $gateway['label'] }}</p>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">{{ $gateway['description'] }}</p>
                    </div>

                    @if($key === 'mpesa')
                        <svg class="w-6 h-6 text-orange-500 flex-shrink-0">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" fill="none"/>
                            <circle cx="12" cy="12" r="4" fill="currentColor"/>
                        </svg>
                    @elseif($key === 'stripe')
                        <svg class="w-6 h-6 text-blue-600 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M13.976 9.15c-2.172.806-3.356 1.954-3.356 3.85 0 1.338.7 2.226 2.25 2.596 1.048.34 2.065.534 3.156.896v2.624c-.033.508-.066 1.01-.11 1.51-.527 3.568-.894 6.914-2.89 8.08-.393.204-.826.3-1.285.3-.593 0-1.132-.229-1.437-.716-.22-.316-.432-.63-.654-.945-.11-.164-.21-.325-.315-.487-.394-.607-.773-1.216-1.228-1.776-.12-.14-.240-.28-.36-.42-.35-.41-.703-.82-1.056-1.23-.073-.083-.147-.165-.22-.248-.434-.505-.868-1.01-1.294-1.526-.159-.188-.317-.376-.476-.564-.32-.383-.64-.767-.96-1.15-.162-.193-.324-.387-.486-.58l-.186-.22c-.12-.144-.24-.288-.36-.432-.308-.382-.616-.763-.922-1.145-.1-.121-.2-.242-.3-.363l-.113-.135c-.28-.35-.56-.7-.842-1.05-.12-.15-.24-.3-.36-.45-.2-.25-.4-.5-.6-.75-.122-.152-.244-.304-.366-.456-.16-.2-.32-.4-.48-.6-.248-.31-.496-.62-.744-.93-.254-.315-.508-.63-.762-.945M13.976 9.15c.068-.42.12-.843.176-1.268.15-1.17.363-2.35.68-3.504.356-1.335.96-2.534 1.88-3.445.46-.463.984-.83 1.566-1.08 1.032-.447 2.173-.65 3.374-.65 1.77 0 3.46.43 5.03 1.3 1.34.739 2.46 1.84 3.24 3.14.49.85.73 1.76.73 2.72 0 .88-.14 1.73-.42 2.54"/>
                        </svg>
                    @elseif($key === 'manual')
                        <svg class="w-6 h-6 text-slate-600 dark:text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    @endif
                </label>

                @if($key === 'mpesa' && $isSelected)
                    <div class="ml-8 mt-4 p-4 bg-purple-50 dark:bg-purple-950 border border-purple-200 dark:border-purple-800 rounded-lg">
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Phone Number</label>
                        <input type="tel" name="phone" placeholder="254712345678" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white text-sm">
                        <p class="text-xs text-slate-600 dark:text-slate-400 mt-2">Format: 254XXXXXXXXX</p>
                    </div>
                @endif
            @empty
                <div class="bg-amber-50 dark:bg-amber-950 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
                    <p class="text-sm text-amber-900 dark:text-amber-100">No payment methods are currently available. Please contact support.</p>
                </div>
            @endforelse
        </div>

        @if($gateways->isNotEmpty())
            <div class="flex gap-3 pt-6">
                <a href="{{ route('reseller.invoices.show', $invoice) }}" class="flex-1 px-4 py-3 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-900 dark:text-white font-medium rounded-lg transition text-center">
                    Back
                </a>
                <button type="submit" class="flex-1 px-4 py-3 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition">
                    Continue to Payment
                </button>
            </div>
        @endif
    </form>
</div>
@endsection

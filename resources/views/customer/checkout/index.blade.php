@extends('layouts.customer')

@section('title', 'Checkout')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Checkout</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Review and place your order</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Order Items -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Order Items</h2>

                <div class="space-y-3">
                    @foreach($cartItems as $item)
                        <div class="flex justify-between items-center p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <div>
                                <p class="font-medium text-slate-900 dark:text-white">{{ $item['name'] }}</p>
                                <p class="text-sm text-slate-500 dark:text-slate-400">{{ $item['description'] }}</p>
                            </div>
                            <p class="font-semibold text-slate-900 dark:text-white">Ksh {{ number_format($item['amount'], 0) }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- Container Product Configuration -->
            @php
                $containerProducts = array_filter($cartItems, fn($item) => $item['type'] === 'container_hosting');
            @endphp
            @if (!empty($containerProducts))
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Container Configuration</h2>

                    <div class="space-y-6">
                        @foreach($containerProducts as $key => $product)
                            @php
                                $template = $product['container_template'] ?? null;
                            @endphp
                            @if ($template && $template->environment_variables)
                                <div class="border-t border-slate-200 dark:border-slate-700 pt-6 first:border-t-0 first:pt-0">
                                    <h3 class="font-semibold text-slate-900 dark:text-white mb-4">{{ $product['name'] }}</h3>

                                    <div class="space-y-4">
                                        @foreach($template->environment_variables as $envVar)
                                            @php
                                                $isRequired = $envVar['required'] ?? false;
                                                $isSecret = $envVar['secret'] ?? false;
                                                $fieldName = "env_values[{$key}][{$envVar['key']}]";
                                                $inputType = $isSecret ? 'password' : 'text';
                                            @endphp

                                            <div>
                                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                                                    {{ $envVar['label'] ?? $envVar['key'] }}
                                                    @if ($isRequired)
                                                        <span class="text-red-600 dark:text-red-400">*</span>
                                                    @endif
                                                </label>

                                                <input
                                                    type="{{ $inputType }}"
                                                    name="{{ $fieldName }}"
                                                    value="{{ old($fieldName, $envVar['default'] ?? '') }}"
                                                    placeholder="{{ $envVar['default'] ?? '' }}"
                                                    {{ $isRequired ? 'required' : '' }}
                                                    class="w-full px-4 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-lg text-slate-900 dark:text-white focus:ring-blue-500 focus:border-blue-500 transition"
                                                />

                                                @if (isset($envVar['description']))
                                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ $envVar['description'] }}</p>
                                                @endif

                                                @error($fieldName)
                                                    <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
                                                @enderror
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Customer Info -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Your Information</h2>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Full Name</label>
                        <p class="text-slate-900 dark:text-white font-medium">{{ $user->name }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Email Address</label>
                        <p class="text-slate-900 dark:text-white font-medium">{{ $user->email }}</p>
                    </div>
                    @if($user->phone)
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Phone Number</label>
                            <p class="text-slate-900 dark:text-white font-medium">{{ $user->phone }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Payment Info -->
            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6">
                <h2 class="text-lg font-bold text-blue-900 dark:text-blue-100 mb-2">Payment Method</h2>
                <p class="text-blue-800 dark:text-blue-200">
                    An invoice will be generated after you place your order. You can pay using M-Pesa, bank transfer, or other available payment methods.
                </p>
            </div>

            <!-- Terms -->
            <form action="{{ route('customer.checkout.process') }}" method="POST" x-data="{ agree: false }">
                @csrf

                <label class="flex items-start gap-3 mb-6 cursor-pointer">
                    <input type="checkbox" name="agree_terms" value="1" required x-model="agree" class="mt-1 rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                    <span class="text-sm text-slate-600 dark:text-slate-400">
                        I agree to the Terms of Service and understand that an invoice will be generated after placing this order
                    </span>
                </label>

                <!-- Submit -->
                <div class="flex gap-3">
                    <a href="{{ route('customer.cart.index') }}" class="flex-1 px-6 py-3 text-center text-slate-700 dark:text-slate-300 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 font-medium transition">
                        Back to Cart
                    </a>
                    <button
                        type="submit"
                        :disabled="!agree"
                        :class="!agree ? 'opacity-50 cursor-not-allowed' : ''"
                        class="flex-1 px-6 py-3 bg-green-600 hover:bg-green-700 disabled:bg-slate-400 text-white rounded-lg font-medium transition"
                    >
                        Place Order
                    </button>
                </div>
            </form>
        </div>

        <!-- Order Summary Sidebar -->
        <div class="lg:col-span-1">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 sticky top-4">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Order Summary</h3>

                <div class="space-y-3 mb-4 pb-4 border-b border-slate-200 dark:border-slate-700">
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-600 dark:text-slate-400">Subtotal</span>
                        <span class="font-medium text-slate-900 dark:text-white">Ksh {{ number_format($subtotal, 0) }}</span>
                    </div>

                    @if($taxEnabled)
                        <div class="flex justify-between text-sm">
                            <span class="text-slate-600 dark:text-slate-400">Tax ({{ $taxRate }}%)</span>
                            <span class="font-medium text-slate-900 dark:text-white">Ksh {{ number_format($tax, 0) }}</span>
                        </div>
                    @endif
                </div>

                <div class="flex justify-between">
                    <span class="font-semibold text-slate-900 dark:text-white">Total</span>
                    <span class="text-2xl font-bold text-green-600 dark:text-green-400">Ksh {{ number_format($total, 0) }}</span>
                </div>

                <div class="mt-6 p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                    <p class="text-xs text-slate-600 dark:text-slate-400">
                        <strong>Note:</strong> Services will be activated automatically once your invoice is marked as paid.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

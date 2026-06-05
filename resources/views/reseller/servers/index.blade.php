@extends('layouts.reseller')

@section('title', 'My Servers')

@section('content')
<div class="space-y-6" x-data="{
    open: false,
    step: 1,
    serverType: null,
}">
    <!-- Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">My Servers</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">View and purchase VPS and dedicated servers at wholesale rates</p>
        </div>
        <button @click="open = true" class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium transition">
            Order Server
        </button>
    </div>

    <!-- My Servers Grid -->
    @if ($services->count() > 0)
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Your Servers</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach ($services as $service)
                    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 hover:shadow-lg transition">
                        <!-- Status Row -->
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-2 h-2 rounded-full" style="background-color: @switch($service->status->value) @case('active') rgb(16, 185, 129) @break @case('pending') rgb(59, 130, 246) @break @case('provisioning') rgb(245, 158, 11) @break @case('suspended') rgb(249, 115, 22) @break @case('terminated') @case('failed') rgb(239, 68, 68) @break @default rgb(107, 114, 128) @endswitch"></span>
                            <span class="text-sm font-medium text-slate-600 dark:text-slate-400">{{ $service->status->label() }}</span>
                        </div>

                        <!-- Type Badge -->
                        <div class="mb-3">
                            <span class="inline-block px-3 py-1 text-xs font-semibold rounded-full" style="background-color: @if($service->product->type === 'vps') rgb(226, 232, 240); color: rgb(30, 41, 59) @else rgb(243, 232, 255); color: rgb(88, 28, 135) @endif">
                                {{ App\Models\Product::typeLabel($service->product->type) }}
                            </span>
                        </div>

                        <!-- Product Name -->
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">{{ $service->product->name }}</h3>

                        <!-- Specs Chips -->
                        @php
                            $limits = $service->product->resource_limits ?? [];
                        @endphp
                        <div class="flex flex-wrap gap-2 mb-4">
                            @if($limits['specs'] ?? null)
                                <span class="px-2.5 py-1 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-md font-mono">{{ $limits['specs'] }}</span>
                            @endif
                            @if($limits['location'] ?? null)
                                <span class="px-2.5 py-1 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-md font-mono">{{ $limits['location'] }}</span>
                            @endif
                            @php
                                $chosenOs  = $service->service_meta['operating_system'] ?? null;
                                $chosenIps = $service->service_meta['ip_count'] ?? null;
                                $osLabels  = config('server_options.linux_distributions', []);
                            @endphp
                            @if($chosenOs)
                                <span class="px-2.5 py-1 text-xs bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 rounded-md font-mono">{{ $osLabels[$chosenOs] ?? $chosenOs }}</span>
                            @endif
                            @if($chosenIps)
                                <span class="px-2.5 py-1 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-md font-mono">{{ $chosenIps }} {{ $chosenIps == 1 ? 'IP' : 'IPs' }}</span>
                            @endif
                        </div>

                        <!-- Info Grid -->
                        <div class="grid grid-cols-2 gap-4 mb-6 pt-4 border-t border-slate-200 dark:border-slate-800">
                            <div>
                                <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Billing Cycle</p>
                                <p class="text-sm font-bold text-slate-900 dark:text-white mt-1">{{ ucfirst($service->billing_cycle) }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Next Due</p>
                                <p class="text-sm font-bold text-slate-900 dark:text-white mt-1">{{ $service->next_due_date?->format('M d, Y') ?? '—' }}</p>
                            </div>
                        </div>

                        <!-- View Button -->
                        <a href="{{ route('customer.services.show', $service) }}" class="w-full inline-block text-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium transition">
                            Manage →
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <!-- Empty State -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-12 text-center">
            <svg class="w-16 h-16 text-slate-300 dark:text-slate-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
            </svg>
            <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-2">No servers yet</h3>
            <p class="text-slate-600 dark:text-slate-400 mb-6">Get started by ordering your first server at wholesale rates</p>
            <button @click="open = true" class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium transition">
                Order Your First Server
            </button>
        </div>
    @endif

    <!-- Order Modal -->
    <div x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center" @click.self="open = false; step = 1; serverType = null">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto" @click.stop>
            <!-- Modal Header -->
            <div class="sticky top-0 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 px-8 py-6 flex justify-between items-center">
                <div>
                    <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Order a Server</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Step <span x-text="step"></span> of 2</p>
                </div>
                <button @click="open = false; step = 1; serverType = null" class="text-slate-500 hover:text-slate-900 dark:hover:text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Modal Content -->
            <div class="p-8">
                <!-- Step 1: Server Type Selection -->
                <div x-show="step === 1" x-transition class="grid grid-cols-2 gap-6">
                    <!-- VPS Card -->
                    <button @click="serverType = 'vps'; step = 2" class="text-left p-8 border-2 border-slate-200 dark:border-slate-700 rounded-xl hover:border-purple-500 dark:hover:border-purple-400 transition">
                        <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-3">VPS Server</h3>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mb-6">Virtual Private Servers with dedicated resources. Perfect for growing applications requiring more power and flexibility.</p>
                        <div class="flex items-center gap-2 text-purple-600 dark:text-purple-400 font-medium">
                            <span>Select VPS</span>
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </div>
                    </button>

                    <!-- Dedicated Server Card -->
                    <button @click="serverType = 'dedicated_server'; step = 2" class="text-left p-8 border-2 border-slate-200 dark:border-slate-700 rounded-xl hover:border-purple-500 dark:hover:border-purple-400 transition">
                        <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-3">Dedicated Server</h3>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mb-6">Entire servers dedicated to your business. Maximum performance and control for demanding applications.</p>
                        <div class="flex items-center gap-2 text-purple-600 dark:text-purple-400 font-medium">
                            <span>Select Dedicated</span>
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </div>
                    </button>
                </div>

                <!-- Step 2: Product Selection -->
                <div x-show="step === 2" x-transition class="space-y-6">
                    <button @click="step = 1; serverType = null" class="flex items-center gap-2 text-purple-600 dark:text-purple-400 hover:text-purple-700 dark:hover:text-purple-300 font-medium mb-4">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                        Back
                    </button>

                    <!-- VPS Products -->
                    <template x-if="serverType === 'vps'">
                        <div class="space-y-4">
                            @forelse ($vpsProducts as $product)
                                <div class="border border-slate-200 dark:border-slate-700 rounded-xl p-5 hover:border-purple-400 transition">
                                    <!-- Product header -->
                                    <div class="flex items-start justify-between gap-4 mb-3">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-3 mb-2">
                                                <h4 class="text-lg font-bold text-slate-900 dark:text-white">{{ $product->name }}</h4>
                                                @if ($product->featured)
                                                    <span class="px-2.5 py-1 text-xs font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 rounded-full">Popular</span>
                                                @endif
                                            </div>
                                            @php $limits = $product->resource_limits ?? []; @endphp
                                            <div class="flex flex-wrap gap-2">
                                                @if($limits['specs'] ?? null)
                                                    <span class="px-2.5 py-1 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-md">{{ $limits['specs'] }}</span>
                                                @endif
                                                @if($limits['location'] ?? null)
                                                    <span class="px-2.5 py-1 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-md">{{ $limits['location'] }}</span>
                                                @endif
                                            </div>
                                        </div>
                                        @if ($product->setup_fee > 0)
                                            <p class="text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap">Setup: {{ $currencyCode }} {{ number_format($product->setup_fee, 0) }}</p>
                                        @endif
                                    </div>

                                    <!-- Single form: OS + IP + billing choice -->
                                    <form action="{{ route('reseller.servers.order') }}" method="POST" class="space-y-3 pt-3 border-t border-slate-100 dark:border-slate-800">
                                        @csrf
                                        <input type="hidden" name="product_id" value="{{ $product->id }}">

                                        <div class="grid grid-cols-2 gap-3">
                                            <!-- OS Selection -->
                                            <div>
                                                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Operating System <span class="text-red-500">*</span></label>
                                                <select name="operating_system" required class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                                                    <option value="">Select OS...</option>
                                                    @foreach($linuxDistros as $osKey => $osLabel)
                                                        <option value="{{ $osKey }}">{{ $osLabel }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <!-- IP Count -->
                                            <div>
                                                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">IP Addresses</label>
                                                <select name="ip_count" class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                                                    @for($i = 1; $i <= $maxIpCount; $i++)
                                                        <option value="{{ $i }}">{{ $i }} {{ $i === 1 ? 'IP' : 'IPs' }}</option>
                                                    @endfor
                                                </select>
                                            </div>
                                        </div>

                                        <!-- Billing Cycle Buttons (named submit buttons) -->
                                        <div class="flex gap-2 pt-1">
                                            <button type="submit" name="billing_cycle" value="monthly" class="flex-1 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition text-center leading-tight">
                                                <span class="block text-xs opacity-80">Wholesale Monthly</span>
                                                <span class="font-bold">{{ $currencyCode }} {{ number_format($product->wholesale_monthly_price, 0) }}/mo</span>
                                            </button>
                                            @if ($product->wholesale_yearly_price)
                                                <button type="submit" name="billing_cycle" value="annual" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium transition text-center leading-tight">
                                                    <span class="block text-xs opacity-80">Wholesale Annual — Save!</span>
                                                    <span class="font-bold">{{ $currencyCode }} {{ number_format($product->wholesale_yearly_price, 0) }}/yr</span>
                                                </button>
                                            @endif
                                        </div>
                                    </form>
                                </div>
                            @empty
                                <p class="text-center text-slate-600 dark:text-slate-400 py-8">No VPS products currently available</p>
                            @endforelse
                        </div>
                    </template>

                    <!-- Dedicated Server Products -->
                    <template x-if="serverType === 'dedicated_server'">
                        <div class="space-y-4">
                            @forelse ($dedicatedProducts as $product)
                                <div class="border border-slate-200 dark:border-slate-700 rounded-xl p-5 hover:border-purple-400 transition">
                                    <!-- Product header -->
                                    <div class="flex items-start justify-between gap-4 mb-3">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-3 mb-2">
                                                <h4 class="text-lg font-bold text-slate-900 dark:text-white">{{ $product->name }}</h4>
                                                @if ($product->featured)
                                                    <span class="px-2.5 py-1 text-xs font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 rounded-full">Popular</span>
                                                @endif
                                            </div>
                                            @php $limits = $product->resource_limits ?? []; @endphp
                                            <div class="flex flex-wrap gap-2">
                                                @if($limits['specs'] ?? null)
                                                    <span class="px-2.5 py-1 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-md">{{ $limits['specs'] }}</span>
                                                @endif
                                                @if($limits['location'] ?? null)
                                                    <span class="px-2.5 py-1 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-md">{{ $limits['location'] }}</span>
                                                @endif
                                            </div>
                                        </div>
                                        @if ($product->setup_fee > 0)
                                            <p class="text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap">Setup: {{ $currencyCode }} {{ number_format($product->setup_fee, 0) }}</p>
                                        @endif
                                    </div>

                                    <!-- Single form: OS + IP + billing choice -->
                                    <form action="{{ route('reseller.servers.order') }}" method="POST" class="space-y-3 pt-3 border-t border-slate-100 dark:border-slate-800">
                                        @csrf
                                        <input type="hidden" name="product_id" value="{{ $product->id }}">

                                        <div class="grid grid-cols-2 gap-3">
                                            <!-- OS Selection -->
                                            <div>
                                                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Operating System <span class="text-red-500">*</span></label>
                                                <select name="operating_system" required class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                                                    <option value="">Select OS...</option>
                                                    @foreach($linuxDistros as $osKey => $osLabel)
                                                        <option value="{{ $osKey }}">{{ $osLabel }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <!-- IP Count -->
                                            <div>
                                                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">IP Addresses</label>
                                                <select name="ip_count" class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                                                    @for($i = 1; $i <= $maxIpCount; $i++)
                                                        <option value="{{ $i }}">{{ $i }} {{ $i === 1 ? 'IP' : 'IPs' }}</option>
                                                    @endfor
                                                </select>
                                            </div>
                                        </div>

                                        <!-- Billing Cycle Buttons (named submit buttons) -->
                                        <div class="flex gap-2 pt-1">
                                            <button type="submit" name="billing_cycle" value="monthly" class="flex-1 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition text-center leading-tight">
                                                <span class="block text-xs opacity-80">Wholesale Monthly</span>
                                                <span class="font-bold">{{ $currencyCode }} {{ number_format($product->wholesale_monthly_price, 0) }}/mo</span>
                                            </button>
                                            @if ($product->wholesale_yearly_price)
                                                <button type="submit" name="billing_cycle" value="annual" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium transition text-center leading-tight">
                                                    <span class="block text-xs opacity-80">Wholesale Annual — Save!</span>
                                                    <span class="font-bold">{{ $currencyCode }} {{ number_format($product->wholesale_yearly_price, 0) }}/yr</span>
                                                </button>
                                            @endif
                                        </div>
                                    </form>
                                </div>
                            @empty
                                <p class="text-center text-slate-600 dark:text-slate-400 py-8">No dedicated server products currently available</p>
                            @endforelse
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

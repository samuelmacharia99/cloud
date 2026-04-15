@extends('layouts.customer')

@section('title', 'My Servers')

@section('content')
<div class="space-y-6" x-data="{
    open: false,
    step: 1,
    serverType: null,
    showTypeSelector: {{ $selectedType ? 'false' : 'true' }},
    selectType(type) {
        window.location.href = '{{ route('customer.servers.index') }}?type=' + type;
    }
}">
    <!-- Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">My Servers</h1>
            <div class="flex items-center gap-3 mt-1">
                <p class="text-slate-600 dark:text-slate-400">
                    @if($selectedType)
                        Viewing <strong>{{ App\Models\Product::typeLabel($selectedType) }}</strong> servers
                    @else
                        View and manage your VPS and dedicated servers
                    @endif
                </p>
                @if($selectedType)
                    <a href="{{ route('customer.servers.index') }}" class="text-xs px-3 py-1 bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-full hover:bg-slate-300 dark:hover:bg-slate-600 transition">
                        Clear Filter
                    </a>
                @endif
            </div>
        </div>
        <button @click="open = true" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
            Order Server
        </button>
    </div>

    <!-- Type Selection Modal (shows if no type selected) -->
    <div x-show="showTypeSelector" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center" style="display: none;">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl max-w-2xl w-full mx-4 p-8 md:p-12">
            <!-- Header -->
            <div class="text-center mb-8">
                <h2 class="text-3xl font-bold text-slate-900 dark:text-white mb-2">Select Server Type</h2>
                <p class="text-slate-600 dark:text-slate-400">Choose the type of server you'd like to view or purchase</p>
            </div>

            <!-- Options Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- VPS Option -->
                <button @click="selectType('vps')" class="group relative overflow-hidden rounded-xl border-2 border-slate-200 dark:border-slate-700 p-8 text-center text-left transition-all hover:border-blue-500 dark:hover:border-blue-400 hover:shadow-lg dark:hover:bg-slate-800/50">
                    <div class="relative z-10">
                        <!-- Icon -->
                        <div class="flex justify-start mb-6">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center group-hover:bg-blue-200 dark:group-hover:bg-blue-900/50 transition">
                                <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                                </svg>
                            </div>
                        </div>

                        <!-- Title -->
                        <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-2">VPS Server</h3>

                        <!-- Description -->
                        <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed">
                            Virtual Private Servers with dedicated resources. Perfect for applications requiring more power and flexibility.
                        </p>

                        <!-- Arrow -->
                        <div class="flex items-center gap-2 text-blue-600 font-medium mt-4">
                            <span>View VPS Servers</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </div>
                    </div>

                    <!-- Background gradient -->
                    <div class="absolute inset-0 bg-gradient-to-br from-blue-50 to-transparent dark:from-blue-900/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                </button>

                <!-- Dedicated Server Option -->
                <button @click="selectType('dedicated_server')" class="group relative overflow-hidden rounded-xl border-2 border-slate-200 dark:border-slate-700 p-8 text-center text-left transition-all hover:border-purple-500 dark:hover:border-purple-400 hover:shadow-lg dark:hover:bg-slate-800/50">
                    <div class="relative z-10">
                        <!-- Icon -->
                        <div class="flex justify-start mb-6">
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/30 rounded-lg flex items-center justify-center group-hover:bg-purple-200 dark:group-hover:bg-purple-900/50 transition">
                                <svg class="w-6 h-6 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                                </svg>
                            </div>
                        </div>

                        <!-- Title -->
                        <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-2">Dedicated Server</h3>

                        <!-- Description -->
                        <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed">
                            Entire servers dedicated to your business. Maximum performance and control for demanding applications.
                        </p>

                        <!-- Arrow -->
                        <div class="flex items-center gap-2 text-purple-600 font-medium mt-4">
                            <span>View Dedicated Servers</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </div>
                    </div>

                    <!-- Background gradient -->
                    <div class="absolute inset-0 bg-gradient-to-br from-purple-50 to-transparent dark:from-purple-900/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                </button>
            </div>
        </div>
    </div>

    <!-- Servers Grid or Empty State -->
    @if ($services->count() > 0)
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach ($services as $service)
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 hover:shadow-lg transition">
                    <!-- Status Row -->
                    <div class="flex items-center gap-3 mb-4">
                        <span class="w-2 h-2 rounded-full" style="background-color: @switch($service->status) @case('active') rgb(16, 185, 129) @break @case('pending') rgb(59, 130, 246) @break @case('provisioning') rgb(245, 158, 11) @break @case('suspended') rgb(249, 115, 22) @break @case('terminated') @case('failed') rgb(239, 68, 68) @break @default rgb(107, 114, 128) @endswitch"></span>
                        <span class="text-sm font-medium capitalize text-slate-600 dark:text-slate-400">{{ ucfirst($service->status) }}</span>
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
                        @if($limits['os'] ?? null)
                            <span class="px-2.5 py-1 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-md font-mono">{{ $limits['os'] }}</span>
                        @endif
                        @if($limits['location'] ?? null)
                            <span class="px-2.5 py-1 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-md font-mono">{{ $limits['location'] }}</span>
                        @endif
                        @php
                            $ipAddress = $service->service_meta['ip_address'] ?? $service->service_meta['ip'] ?? null;
                        @endphp
                        @if($ipAddress)
                            <span class="px-2.5 py-1 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-md font-mono">{{ $ipAddress }}</span>
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

                    <!-- Action Button -->
                    <a href="{{ route('customer.services.show', $service) }}" class="w-full inline-block text-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                        Manage →
                    </a>
                </div>
            @endforeach
        </div>
    @else
        <!-- Empty State -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-12 text-center">
            <svg class="w-16 h-16 text-slate-300 dark:text-slate-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"></path>
            </svg>
            <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-2">No servers yet</h3>
            <p class="text-slate-600 dark:text-slate-400 mb-6">Get started by ordering your first server</p>
            <button @click="open = true" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                Order Your First Server
            </button>
        </div>
    @endif

    <!-- Order Modal -->
    <div x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center" @click.self="open=false; step=1; serverType=null">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto" @click.stop>
            <!-- Modal Header -->
            <div class="sticky top-0 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 px-8 py-6 flex justify-between items-center">
                <div>
                    <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Order a Server</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Step <span x-text="step"></span> of 2</p>
                </div>
                <button @click="open=false; step=1; serverType=null" class="text-slate-500 hover:text-slate-900 dark:hover:text-white">
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
                    <button @click="serverType='vps'; step=2" class="text-left p-8 border-2 border-slate-200 dark:border-slate-700 rounded-xl hover:border-blue-500 transition">
                        <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-3">VPS Server</h3>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mb-6">Perfect for growing applications. Full root access with isolated resources.</p>
                        <div class="flex items-center gap-2 text-blue-600 font-medium">
                            <span>Select VPS</span>
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </div>
                    </button>

                    <!-- Dedicated Server Card -->
                    <button @click="serverType='dedicated_server'; step=2" class="text-left p-8 border-2 border-slate-200 dark:border-slate-700 rounded-xl hover:border-blue-500 transition">
                        <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-3">Dedicated Server</h3>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mb-6">Maximum performance. Entire server dedicated to your application.</p>
                        <div class="flex items-center gap-2 text-blue-600 font-medium">
                            <span>Select Dedicated</span>
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </div>
                    </button>
                </div>

                <!-- Step 2: Product Selection -->
                <div x-show="step === 2" x-transition class="space-y-6">
                    <button @click="step=1; serverType=null" class="flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium mb-4">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                        Back
                    </button>

                    <!-- VPS Products -->
                    <template x-if="serverType === 'vps'">
                        <div class="space-y-4">
                            @forelse ($vpsProducts as $product)
                                <div class="border border-slate-200 dark:border-slate-700 rounded-xl hover:border-blue-400 transition p-5 flex justify-between items-start gap-4">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3 mb-2">
                                            <h4 class="text-lg font-bold text-slate-900 dark:text-white">{{ $product->name }}</h4>
                                            @if ($product->featured)
                                                <span class="px-2.5 py-1 text-xs font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 rounded-full">Popular</span>
                                            @endif
                                        </div>
                                        
                                        @php
                                            $limits = $product->resource_limits ?? [];
                                        @endphp
                                        <div class="flex flex-wrap gap-2 mt-3">
                                            @if($limits['specs'] ?? null)
                                                <span class="px-2.5 py-1 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-md">{{ $limits['specs'] }}</span>
                                            @endif
                                            @if($limits['os'] ?? null)
                                                <span class="px-2.5 py-1 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-md">{{ $limits['os'] }}</span>
                                            @endif
                                            @if($limits['location'] ?? null)
                                                <span class="px-2.5 py-1 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-md">{{ $limits['location'] }}</span>
                                            @endif
                                        </div>
                                        
                                        @if ($product->setup_fee > 0)
                                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Setup fee: {{ $currencySymbol }} {{ number_format($product->setup_fee, 0) }}</p>
                                        @endif
                                    </div>

                                    <div class="space-y-2 sm:min-w-[200px]">
                                        <!-- Monthly Option -->
                                        <form action="{{ route('customer.servers.order') }}" method="POST">
                                            @csrf
                                            <input type="hidden" name="product_id" value="{{ $product->id }}">
                                            <input type="hidden" name="billing_cycle" value="monthly">
                                            <div class="text-right mb-2">
                                                <p class="text-sm text-slate-600 dark:text-slate-400">Monthly</p>
                                                <p class="text-lg font-bold text-slate-900 dark:text-white">{{ $currencySymbol }} {{ number_format($product->monthly_price, 0) }}/mo</p>
                                            </div>
                                            <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">
                                                Order Monthly
                                            </button>
                                        </form>

                                        <!-- Annual Option -->
                                        @if ($product->yearly_price)
                                            <form action="{{ route('customer.servers.order') }}" method="POST">
                                                @csrf
                                                <input type="hidden" name="product_id" value="{{ $product->id }}">
                                                <input type="hidden" name="billing_cycle" value="annual">
                                                <div class="text-right mb-2">
                                                    <p class="text-sm text-emerald-600 dark:text-emerald-400">Yearly</p>
                                                    <p class="text-lg font-bold text-emerald-600 dark:text-emerald-400">{{ $currencySymbol }} {{ number_format($product->yearly_price, 0) }}/yr</p>
                                                </div>
                                                <button type="submit" class="w-full px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium transition">
                                                    Order Annually
                                                </button>
                                            </form>
                                        @endif
                                    </div>
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
                                <div class="border border-slate-200 dark:border-slate-700 rounded-xl hover:border-blue-400 transition p-5 flex justify-between items-start gap-4">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3 mb-2">
                                            <h4 class="text-lg font-bold text-slate-900 dark:text-white">{{ $product->name }}</h4>
                                            @if ($product->featured)
                                                <span class="px-2.5 py-1 text-xs font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 rounded-full">Popular</span>
                                            @endif
                                        </div>
                                        
                                        @php
                                            $limits = $product->resource_limits ?? [];
                                        @endphp
                                        <div class="flex flex-wrap gap-2 mt-3">
                                            @if($limits['specs'] ?? null)
                                                <span class="px-2.5 py-1 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-md">{{ $limits['specs'] }}</span>
                                            @endif
                                            @if($limits['os'] ?? null)
                                                <span class="px-2.5 py-1 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-md">{{ $limits['os'] }}</span>
                                            @endif
                                            @if($limits['location'] ?? null)
                                                <span class="px-2.5 py-1 text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-md">{{ $limits['location'] }}</span>
                                            @endif
                                        </div>
                                        
                                        @if ($product->setup_fee > 0)
                                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Setup fee: {{ $currencySymbol }} {{ number_format($product->setup_fee, 0) }}</p>
                                        @endif
                                    </div>

                                    <div class="space-y-2 sm:min-w-[200px]">
                                        <!-- Monthly Option -->
                                        <form action="{{ route('customer.servers.order') }}" method="POST">
                                            @csrf
                                            <input type="hidden" name="product_id" value="{{ $product->id }}">
                                            <input type="hidden" name="billing_cycle" value="monthly">
                                            <div class="text-right mb-2">
                                                <p class="text-sm text-slate-600 dark:text-slate-400">Monthly</p>
                                                <p class="text-lg font-bold text-slate-900 dark:text-white">{{ $currencySymbol }} {{ number_format($product->monthly_price, 0) }}/mo</p>
                                            </div>
                                            <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">
                                                Order Monthly
                                            </button>
                                        </form>

                                        <!-- Annual Option -->
                                        @if ($product->yearly_price)
                                            <form action="{{ route('customer.servers.order') }}" method="POST">
                                                @csrf
                                                <input type="hidden" name="product_id" value="{{ $product->id }}">
                                                <input type="hidden" name="billing_cycle" value="annual">
                                                <div class="text-right mb-2">
                                                    <p class="text-sm text-emerald-600 dark:text-emerald-400">Yearly</p>
                                                    <p class="text-lg font-bold text-emerald-600 dark:text-emerald-400">{{ $currencySymbol }} {{ number_format($product->yearly_price, 0) }}/yr</p>
                                                </div>
                                                <button type="submit" class="w-full px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium transition">
                                                    Order Annually
                                                </button>
                                            </form>
                                        @endif
                                    </div>
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

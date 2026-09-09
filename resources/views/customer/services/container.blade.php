@extends('layouts.customer')

@section('title', 'Application: ' . $service->name)

@section('content')
<div class="bg-gradient-to-br from-slate-50 to-slate-100 dark:from-slate-900 dark:to-slate-800 min-h-screen py-8">
    <div class="max-w-7xl mx-auto px-4">
        <!-- Header -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-lg p-8 mb-8">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="flex items-center gap-4">
                        <div>
                            <h1 class="text-4xl font-bold text-slate-900 dark:text-white">{{ $service->name }}</h1>
                            <p class="text-slate-600 dark:text-slate-400 mt-2">{{ $service->product->containerTemplate->name ?? 'Application Service' }}</p>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    @php
                        $statusConfig = match($deployment?->status) {
                            'running'   => ['pulse' => 'bg-green-400',  'ring' => 'bg-green-500',  'text' => 'Running',   'textClass' => 'text-green-700 dark:text-green-300',  'bg' => 'bg-green-50 dark:bg-green-900/30',  'border' => 'border-green-200 dark:border-green-700'],
                            'stopped'   => ['pulse' => null,             'ring' => 'bg-yellow-400', 'text' => 'Stopped',   'textClass' => 'text-yellow-700 dark:text-yellow-300', 'bg' => 'bg-yellow-50 dark:bg-yellow-900/30', 'border' => 'border-yellow-200 dark:border-yellow-700'],
                            'deploying' => ['pulse' => 'bg-blue-400',   'ring' => 'bg-blue-500',   'text' => 'Deploying', 'textClass' => 'text-blue-700 dark:text-blue-300',    'bg' => 'bg-blue-50 dark:bg-blue-900/30',    'border' => 'border-blue-200 dark:border-blue-700'],
                            'failed'    => ['pulse' => null,             'ring' => 'bg-red-500',    'text' => 'Failed',    'textClass' => 'text-red-700 dark:text-red-300',      'bg' => 'bg-red-50 dark:bg-red-900/30',      'border' => 'border-red-200 dark:border-red-700'],
                            default     => ['pulse' => null,             'ring' => 'bg-slate-400',  'text' => 'Pending',   'textClass' => 'text-slate-700 dark:text-slate-300',  'bg' => 'bg-slate-50 dark:bg-slate-800',     'border' => 'border-slate-200 dark:border-slate-700'],
                        };
                    @endphp
                    <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-semibold {{ $statusConfig['bg'] }} {{ $statusConfig['border'] }} border {{ $statusConfig['textClass'] }}">
                        <span class="relative flex h-2 w-2">
                            @if($statusConfig['pulse'])
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full {{ $statusConfig['pulse'] }} opacity-75"></span>
                            @endif
                            <span class="relative inline-flex rounded-full h-2 w-2 {{ $statusConfig['ring'] }}"></span>
                        </span>
                        {{ $statusConfig['text'] }}
                    </span>

                    @if ($deployment)
                        @include('services.partials.container-redeploy-modal')
                    @endif

                    <a href="{{ route('customer.services.index') }}" class="px-4 py-2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition">
                        ← Services
                    </a>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        @if ($message = Session::get('success'))
            <div class="mb-6 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 rounded-lg">
                {{ $message }}
            </div>
        @endif

        @if ($message = Session::get('error'))
            <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 rounded-lg">
                {{ $message }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 rounded-lg">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        @include('services.partials.container-console')

        @if (! in_array($service->status->value, ['terminated', 'cancelled']))
            <div
                class="mt-8 bg-white dark:bg-slate-800 rounded-2xl shadow-lg border border-red-200 dark:border-red-900/40 p-8"
                x-data="{ showDeleteModal: false, confirmName: '' }"
            >
                <h2 class="text-lg font-semibold text-red-700 dark:text-red-400">Danger Zone</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-2 max-w-2xl">
                    Permanently delete this service and shut down the app. All data will be removed and this cannot be undone.
                </p>
                <button
                    type="button"
                    @click="showDeleteModal = true; confirmName = ''"
                    class="mt-4 px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition text-sm"
                >
                    Delete Service
                </button>

                <div x-show="showDeleteModal" x-cloak class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
                    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl max-w-md w-full p-6" @click.stop>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-2">Delete Service</h3>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
                            This will terminate the app and remove the service from your account. Type
                            <span class="font-mono font-semibold text-slate-900 dark:text-white">{{ $service->name }}</span>
                            to confirm.
                        </p>

                        <form method="POST" action="{{ container_route('destroy', $service) }}">
                            @csrf
                            @method('DELETE')
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Service name</label>
                            <input
                                type="text"
                                name="service_name"
                                x-model="confirmName"
                                autocomplete="off"
                                class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 rounded-lg text-sm focus:ring-2 focus:ring-red-500 dark:focus:ring-red-400 mb-4"
                                placeholder="Type service name exactly"
                            >

                            <div class="flex gap-3">
                                <button
                                    type="button"
                                    @click="showDeleteModal = false"
                                    class="flex-1 px-4 py-2 bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 font-medium rounded-lg hover:bg-slate-200 dark:hover:bg-slate-600 transition"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="confirmName !== @js($service->name)"
                                    class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-medium rounded-lg transition"
                                >
                                    Delete Service
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>

@include('services.partials.container-console-scripts')
@endsection

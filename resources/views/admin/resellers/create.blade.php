@extends('layouts.admin')

@section('title', 'Create Reseller')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.resellers.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Resellers</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Create Reseller</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Create Reseller</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Add a new reseller account to the system.</p>
    </div>

    <!-- Form -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <form method="POST" action="{{ route('admin.resellers.store') }}" class="space-y-8">
            @csrf

            <!-- Two-column layout -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Left Column -->
                <div class="space-y-6">
                    <!-- Full Name -->
                    <div>
                        <label for="name" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Full Name</label>
                        <input type="text" id="name" name="name" value="{{ old('name') }}" placeholder="John Doe" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('name') border-red-500 @enderror" required>
                        @error('name')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Email -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Email Address</label>
                        <input type="email" id="email" name="email" value="{{ old('email') }}" placeholder="reseller@example.com" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('email') border-red-500 @enderror" required>
                        @error('email')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Company -->
                    <div>
                        <label for="company" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Company <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <input type="text" id="company" name="company" value="{{ old('company') }}" placeholder="Company Name Inc." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        @error('company')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Country -->
                    <div>
                        <label for="country" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Country <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <input type="text" id="country" name="country" value="{{ old('country') }}" placeholder="Kenya" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        @error('country')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Phone -->
                    <div>
                        <label for="phone" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Phone <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" placeholder="+254 712 345 678" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
                        @error('phone')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Notes -->
                    <div>
                        <label for="notes" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Notes <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <textarea id="notes" name="notes" rows="5" placeholder="Add any internal notes about this reseller..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm resize-none">{{ old('notes') }}</textarea>
                        @error('notes')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    @include('admin.partials.send-welcome-email-checkbox')
                </div>

                <!-- Right Column -->
                <div class="space-y-6" x-data="{
                    showPassword: false,
                    showConfirmPassword: false,
                    generating: false,
                    generatedHint: false,
                    async generatePassword() {
                        this.generating = true;
                        try {
                            const res = await fetch('{{ route('admin.customers.generate-password') }}?length=16', {
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            });
                            if (!res.ok) throw new Error('Failed');
                            const data = await res.json();
                            if (!data.password) throw new Error('Empty');
                            document.getElementById('password').value = data.password;
                            document.getElementById('password_confirmation').value = data.password;
                            this.showPassword = true;
                            this.showConfirmPassword = true;
                            this.generatedHint = true;
                        } catch {
                            alert('Failed to generate password. Please try again.');
                        } finally {
                            this.generating = false;
                        }
                    }
                }">
                    <!-- Password -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Password</label>
                        <div class="relative">
                            <input
                                :type="showPassword ? 'text' : 'password'"
                                id="password"
                                name="password"
                                placeholder="Enter a secure password"
                                class="w-full px-4 py-2 pr-20 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('password') border-red-500 @enderror"
                                required>
                            <div class="absolute right-2 top-1/2 -translate-y-1/2 flex items-center gap-0.5">
                                <button
                                    type="button"
                                    @click="generatePassword()"
                                    :disabled="generating"
                                    class="p-1.5 text-slate-500 dark:text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 disabled:opacity-50 transition rounded-md hover:bg-slate-100 dark:hover:bg-slate-700"
                                    title="Generate password"
                                    aria-label="Generate password">
                                    <svg class="w-4 h-4" :class="{ 'animate-spin': generating }" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                    </svg>
                                </button>
                                <button
                                    type="button"
                                    @click="showPassword = !showPassword"
                                    class="p-1.5 text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition rounded-md hover:bg-slate-100 dark:hover:bg-slate-700"
                                    :title="showPassword ? 'Hide password' : 'Show password'"
                                    :aria-label="showPassword ? 'Hide password' : 'Show password'">
                                    <svg x-show="!showPassword" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                    <svg x-show="showPassword" x-cloak class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-4.803m5.596-3.856a3.375 3.375 0 11-4.753 4.753m4.753-4.753L3.596 3.596m16.807 16.807L6.404 6.404m9.596 9.596a3 3 0 10-4.242-4.242m4.242 4.242L9.172 9.172"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        @error('password')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                        <p x-show="generatedHint" x-cloak class="mt-1 text-xs text-emerald-600 dark:text-emerald-400">Password generated — copy it before submitting.</p>
                    </div>

                    <!-- Confirm Password -->
                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Confirm Password</label>
                        <div class="relative">
                            <input
                                :type="showConfirmPassword ? 'text' : 'password'"
                                id="password_confirmation"
                                name="password_confirmation"
                                placeholder="Confirm password"
                                class="w-full px-4 py-2 pr-10 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm"
                                required>
                            <button
                                type="button"
                                @click="showConfirmPassword = !showConfirmPassword"
                                class="absolute right-2 top-1/2 -translate-y-1/2 p-1.5 text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition rounded-md hover:bg-slate-100 dark:hover:bg-slate-700"
                                :title="showConfirmPassword ? 'Hide password' : 'Show password'"
                                :aria-label="showConfirmPassword ? 'Hide password' : 'Show password'">
                                <svg x-show="!showConfirmPassword" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                <svg x-show="showConfirmPassword" x-cloak class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-4.803m5.596-3.856a3.375 3.375 0 11-4.753 4.753m4.753-4.753L3.596 3.596m16.807 16.807L6.404 6.404m9.596 9.596a3 3 0 10-4.242-4.242m4.242 4.242L9.172 9.172"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Reseller Package Section Header -->
                    <div class="pt-6 border-t border-slate-200 dark:border-slate-800">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Reseller Package</h3>
                    </div>

                    <!-- Package Selection -->
                    <div>
                        <label for="reseller_package_id" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Package <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <select id="reseller_package_id" name="reseller_package_id" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('reseller_package_id') border-red-500 @enderror">
                            <option value="">— No package yet —</option>
                            @foreach ($packages->groupBy('billing_cycle') as $cycle => $cyclePackages)
                                <optgroup label="{{ ucfirst($cycle) }}">
                                    @foreach ($cyclePackages as $package)
                                        <option value="{{ $package->id }}" @selected(old('reseller_package_id') == $package->id)>
                                            {{ $package->name }} — Ksh {{ number_format($package->price, 0) }}/{{ $cycle === 'monthly' ? 'mo' : 'yr' }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @error('reseller_package_id')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">You can change or assign a package later.</p>
                    </div>

                    @include('admin.resellers.partials.directadmin-fields', ['user' => new \App\Models\User()])
                </div>
            </div>

            <!-- Form Actions -->
            <div class="flex items-center justify-between pt-6 border-t border-slate-200 dark:border-slate-800">
                <a href="{{ route('admin.resellers.index') }}" class="px-6 py-2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium transition">
                    Cancel
                </a>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                    Create Reseller
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
